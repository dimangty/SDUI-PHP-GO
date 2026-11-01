<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** Required work only. Optional dependencies never receive a transport callback.
 * @phpstan-type ReadyResult array{status: int, data: array{status: string, components: array<string,string>, capabilities?: list<string>}}
 */
final class ReadinessPolicy
{
    public function __construct(
        private readonly ReadinessBudget $budget=new ReadinessBudget(),
        private readonly ReadinessAdmission $admission=new ReadinessAdmission(),
    ) {}

    /**
     * @param callable(float, callable(): float, callable(): bool): void $probe
     * @param callable(): float $clock Monotonic seconds.
     * @param callable(): bool $cancel True when the caller has gone away.
     * @return ReadyResult
     */
    public function evaluate(callable $probe,callable $clock,callable $cancel,bool $searchConfigured): array
    {
        $components=$searchConfigured?['opensearch'=>'unknown']:[];
        $lease=null;
        try {
            $started=$clock();$deadline=$this->budget->deadline($started);
            if (!is_finite($started)) { throw new \RuntimeException('Readiness clock invalid'); }
            $this->checkCancellation($cancel);
            $lease=$this->admission->acquire();
            if ($lease===null) { throw new \RuntimeException('Readiness admission saturated'); }
            $probe($deadline,$clock,$cancel);
            $finished=$clock();
            if (!is_finite($finished) || $finished<$started || $finished>=$deadline) {
                throw new \RuntimeException('Readiness deadline exceeded');
            }
            $this->checkCancellation($cancel);
            return ['status'=>200,'data'=>['status'=>'ok','components'=>['postgres'=>'up','local-auth'=>'up']+$components,'capabilities'=>['catalog-cards-v1']]];
        } catch (\Throwable) {
            return ['status'=>503,'data'=>['status'=>'unavailable','components'=>$components+['dependency'=>'down']]];
        } finally {
            // The probe's native finally has ended actual work before this lease
            // closes, even when FPM never observes the upstream disconnect.
            if (is_resource($lease)) { fclose($lease); }
        }
    }

    /** @param callable(): bool $cancel */
    private function checkCancellation(callable $cancel): void
    {
        if ($cancel()) { throw new \RuntimeException('Readiness cancelled'); }
    }
}
