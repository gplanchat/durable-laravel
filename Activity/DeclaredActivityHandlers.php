<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Activity;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;
use Gplanchat\Durable\Attribute\AsActivityHandler;
use Gplanchat\Durable\RegistryActivityExecutor;
use Illuminate\Contracts\Container\Container;

/**
 * The activity handlers `config/durable.php` declares, carried onto the executor: the counterpart
 * of Symfony's `ActivityHandlerPass` and Magento's `activityHandlers`. The contract is the one
 * `#[AsActivityHandler]` names, else the handler's activity interfaces. The table is built by
 * reflection when the provider registers; a handler is built only when one of its activities runs.
 */
final class DeclaredActivityHandlers
{
    /** @var array<string, array{class-string, class-string, string}> activity => handler, contract, method */
    private array $table = [];

    /** @param list<string> $handlers */
    public function __construct(array $handlers)
    {
        $resolver = new ActivityContractResolver();

        foreach ($handlers as $handler) {
            foreach (self::contractsOf($handler) as $contract) {
                foreach ($resolver->resolveActivityMethods($contract) as $method => $activity) {
                    $this->table[$activity] = [$handler, $contract, $method];
                }
            }
        }
    }

    public function registerInto(RegistryActivityExecutor $executor, Container $container): RegistryActivityExecutor
    {
        foreach ($this->table as $activity => [$handler, $contract, $method]) {
            $executor->register(
                $activity,
                static fn(array $payload): mixed => (new PayloadToContractMethodInvoker($container->make($handler), $contract, $method))($payload),
            );
        }

        return $executor;
    }

    /**
     * @param class-string $handler
     *
     * @return list<class-string>
     */
    private static function contractsOf(string $handler): array
    {
        $named = (new \ReflectionClass($handler))->getAttributes(AsActivityHandler::class);
        if ([] !== $named) {
            return [$named[0]->newInstance()->contract];
        }

        return array_values(class_implements($handler) ?: []);
    }
}
