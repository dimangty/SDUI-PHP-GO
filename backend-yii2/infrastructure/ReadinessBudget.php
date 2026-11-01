<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** Milliseconds. The PHP work fits inside the BFF total, with explicit reserves. */
final readonly class ReadinessBudget
{
    public function __construct(
        public int $coreMilliseconds=700,
        public int $totalMilliseconds=2000,
        public int $queueMilliseconds=700,
        public int $httpMilliseconds=400,
        public int $serializationMilliseconds=200,
        public int $cleanupMilliseconds=100,
        public int $statementMilliseconds=250,
        public int $lockMilliseconds=100,
    ) {
        if ($coreMilliseconds<=0 || $totalMilliseconds<=0 || $queueMilliseconds<0
            || $httpMilliseconds<=0 || $serializationMilliseconds<=0 || $cleanupMilliseconds<=0
            || $cleanupMilliseconds>=$coreMilliseconds || $statementMilliseconds<=0
            || $statementMilliseconds>$coreMilliseconds-$cleanupMilliseconds
            || $lockMilliseconds<=0 || $lockMilliseconds>$statementMilliseconds
            || $coreMilliseconds+$queueMilliseconds+$httpMilliseconds+$serializationMilliseconds>$totalMilliseconds) {
            throw new \InvalidArgumentException('Invalid readiness budget');
        }
    }

    public function deadline(float $started): float
    {
        return $started+($this->coreMilliseconds-$this->cleanupMilliseconds)/1000;
    }
}
