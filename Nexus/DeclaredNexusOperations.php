<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Nexus;

use Gplanchat\Durable\Attribute\AsNexusServiceHandler;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;
use Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames;
use Gplanchat\Durable\Nexus\Serving\NexusHandlerInvoker;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Illuminate\Contracts\Container\Container;

/**
 * What the application declares it serves in Nexus, carried into the core's registry.
 *
 * This is the counterpart of `NexusHandlerPass` on the Symfony side, and it does the same work by
 * the same path — `NexusContractResolver` to read the contract, `NexusHandlerInvoker` to hold
 * between the handler's signature and what the registry calls. What changes is the source: Symfony
 * reads tags an autoconfiguration has set, Laravel reads `config/durable.php`, because its
 * container has no equivalent — the same reason for which the workflows are declared.
 *
 * **An operation without a body is not a missing operation.** A Nexus contract splits into two
 * interfaces because PHP cannot say "partially implements": what the handler does not serve, a
 * workflow fulfils, and it is `#[FulfilsNexusOperation]` that declares it. What is registered then
 * is the **type** of the workflow and not its class — that is the name the server knows and the
 * journal records.
 */
final class DeclaredNexusOperations
{
    /**
     * @param array<array-key, class-string> $handlers  handler => the contract it serves, or a
     *                                                  handler alone, whose contract its
     *                                                  #[AsNexusServiceHandler] names
     * @param list<class-string>             $workflows the declared workflows, where the
     *                                                  operations they fulfil are read
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $handlers = [],
        private readonly array $workflows = [],
    ) {}

    public function registerInto(NexusOperationRegistry $registry): void
    {
        $resolver = new NexusContractResolver(null);
        $claimed = $this->operationsClaimedByWorkflows();

        foreach ($this->handlers as $key => $value) {
            [$handlerClass, $contract] = \is_int($key) ? [$value, self::contractNamedBy($value)] : [$key, $value];
            if (!interface_exists($contract)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: "%s" is declared as the Nexus contract of %s, but no such interface exists. '
                    . 'The key of durable.nexus.handlers is the handler class, the value is the contract '
                    . 'interface it serves.',
                    $contract,
                    $handlerClass,
                ));
            }

            $named = \is_int($key) ? $contract : self::contractNamedBy($handlerClass, required: false);
            if (null !== $named && $named !== $contract) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: durable.nexus.handlers gives %s the contract %s, but its #[AsNexusServiceHandler] names %s.',
                    $handlerClass,
                    $contract,
                    $named,
                ));
            }

            $service = NexusService::named($resolver->serviceName($contract));
            $served = 0;
            $unserved = [];

            foreach ($resolver->operations($contract) as $method => $operation) {
                $name = NexusOperationName::named($operation);

                if (method_exists($handlerClass, $method)) {
                    $invoker = new NexusHandlerInvoker($this->container->make($handlerClass), $contract, $method);
                    $registry->register($service, $name, $invoker(...));
                    ++$served;

                    continue;
                }

                $workflowClass = $claimed[$contract][$operation] ?? null;
                if (null !== $workflowClass) {
                    // The same refusal as on the Symfony side, by the same class: reading a list
                    // from a file does not excuse checking what a compiler pass checks. It falls
                    // here, at registration, and not on the first task — that is the last moment
                    // where somebody is looking.
                    NexusFulfilmentParameterNames::assertMatch(
                        'durable.nexus.handlers',
                        $contract,
                        $method,
                        $operation,
                        $workflowClass,
                    );

                    // The **type**, not the FQCN: that is the name the server knows and that the
                    // journal records.
                    $registry->registerFulfilment(
                        $service,
                        $name,
                        (new WorkflowDefinitionLoader())->workflowTypeForClass($workflowClass),
                    );
                    ++$served;

                    continue;
                }

                $unserved[$operation] = $method . '()';
            }

            if (0 === $served) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: %s serves none of the operations of %s — neither a method nor a workflow '
                    . 'carrying #[FulfilsNexusOperation] answers for any of them. A handler that serves '
                    . 'nothing is a declaration nobody will notice is dead.',
                    $handlerClass,
                    $contract,
                ));
            }

            // Symfony's NexusHandlerPass refuses the same at compile time: a caller would wait on a
            // result nothing produces.
            if ([] !== $unserved) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: operation "%s" of contract %s is served by nobody — handler %s does not implement '
                    . '%s and no workflow claims it with #[FulfilsNexusOperation]. A caller would wait on a result '
                    . 'nothing produces.',
                    implode('", "', array_keys($unserved)),
                    $contract,
                    $handlerClass,
                    implode(', ', $unserved),
                ));
            }
        }
    }

    /** @return ($required is true ? class-string : class-string|null) */
    private static function contractNamedBy(string $handlerClass, bool $required = true): ?string
    {
        if (!class_exists($handlerClass)) {
            throw new \InvalidArgumentException(\sprintf('Durable: "%s" is declared in durable.nexus.handlers, but no such class exists.', $handlerClass));
        }

        $attributes = (new \ReflectionClass($handlerClass))->getAttributes(AsNexusServiceHandler::class);
        if ([] === $attributes && $required) {
            throw new \InvalidArgumentException(\sprintf(
                'Durable: %s is listed alone in durable.nexus.handlers, so its contract must come from '
                . '#[AsNexusServiceHandler]. Add the attribute, or declare it as handler => contract.',
                $handlerClass,
            ));
        }

        return [] === $attributes ? null : $attributes[0]->newInstance()->contract;
    }

    /** @return array<class-string, array<string, string>> contract => operation => workflow type */
    private function operationsClaimedByWorkflows(): array
    {
        $claimed = [];

        foreach ($this->workflows as $workflowClass) {
            if (!class_exists($workflowClass)) {
                throw new \InvalidArgumentException(\sprintf('Durable: "%s" is declared in durable.workflows, but no such class exists.', $workflowClass));
            }

            foreach ((new \ReflectionClass($workflowClass))->getAttributes(FulfilsNexusOperation::class) as $attribute) {
                $fulfils = $attribute->newInstance();
                $claimed[$fulfils->contract][$fulfils->operation] = $workflowClass;
            }
        }

        return $claimed;
    }
}
