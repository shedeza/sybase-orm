<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\IdentityMap;
use stdClass;

/**
 * Property-based test for IdentityMap Composite Key Round-Trip.
 *
 * **Validates: Requirements 3.1, 3.2, 3.3**
 *
 * For any entity class and any composite key (associative array of key-value pairs),
 * storing the entity via put() and then retrieving it via get() with the same key values
 * SHALL return the same entity instance. Furthermore, two different composite key arrays
 * (differing in at least one value) SHALL map to different entries.
 */
final class IdentityMapPropertyTest extends TestCase
{
    /**
     * @dataProvider randomCompositeKeysProvider
     *
     * @param array<string, string|int> $compositeKey
     */
    public function testPutGetRoundTripReturnsSameInstance(array $compositeKey): void
    {
        $map = new IdentityMap();
        $entity = new stdClass();
        $entityClass = 'App\\Entity\\TestEntity';

        $map->put($entityClass, $compositeKey, $entity);
        $retrieved = $map->get($entityClass, $compositeKey);

        $this->assertSame(
            $entity,
            $retrieved,
            sprintf(
                'put() then get() with key %s must return the same entity instance',
                json_encode($compositeKey),
            ),
        );
    }

    /**
     * @dataProvider randomCompositeKeyPairsProvider
     *
     * @param array<string, string|int> $keyA
     * @param array<string, string|int> $keyB
     */
    public function testDifferentCompositeKeysDoNotCollide(array $keyA, array $keyB): void
    {
        $map = new IdentityMap();
        $entityA = new stdClass();
        $entityA->label = 'A';
        $entityB = new stdClass();
        $entityB->label = 'B';
        $entityClass = 'App\\Entity\\TestEntity';

        $map->put($entityClass, $keyA, $entityA);
        $map->put($entityClass, $keyB, $entityB);

        $retrievedA = $map->get($entityClass, $keyA);
        $retrievedB = $map->get($entityClass, $keyB);

        $this->assertSame(
            $entityA,
            $retrievedA,
            sprintf(
                'Key A %s must still retrieve entity A after storing entity B with key B %s',
                json_encode($keyA),
                json_encode($keyB),
            ),
        );

        $this->assertSame(
            $entityB,
            $retrievedB,
            sprintf(
                'Key B %s must retrieve entity B, not entity A with key A %s',
                json_encode($keyB),
                json_encode($keyA),
            ),
        );

        $this->assertNotSame(
            $retrievedA,
            $retrievedB,
            'Different composite keys must map to different entries',
        );
    }

    /**
     * Generates 120 random composite key arrays with 1–5 keys and random string/int values.
     *
     * @return \Generator<string, array{array<string, string|int>}>
     */
    public static function randomCompositeKeysProvider(): \Generator
    {
        for ($i = 0; $i < 120; $i++) {
            $numKeys = mt_rand(1, 5);
            $compositeKey = self::generateRandomCompositeKey($numKeys);

            yield "iteration_{$i}_keys_{$numKeys}" => [$compositeKey];
        }
    }

    /**
     * Generates 120 pairs of different composite key arrays that differ in at least one value.
     *
     * @return \Generator<string, array{array<string, string|int>, array<string, string|int>}>
     */
    public static function randomCompositeKeyPairsProvider(): \Generator
    {
        for ($i = 0; $i < 120; $i++) {
            $numKeys = mt_rand(1, 5);
            $keyA = self::generateRandomCompositeKey($numKeys);

            // Create keyB that differs in at least one value
            $keyB = $keyA;
            $fieldNames = array_keys($keyB);
            $mutateIndex = mt_rand(0, count($fieldNames) - 1);
            $mutateField = $fieldNames[$mutateIndex];

            // Ensure the mutated value is different
            $originalValue = $keyB[$mutateField];
            do {
                $keyB[$mutateField] = self::generateRandomValue();
            } while ($keyB[$mutateField] === $originalValue);

            yield "iteration_{$i}_keys_{$numKeys}" => [$keyA, $keyB];
        }
    }

    /**
     * @return array<string, string|int>
     */
    private static function generateRandomCompositeKey(int $numKeys): array
    {
        $compositeKey = [];
        for ($j = 0; $j < $numKeys; $j++) {
            $fieldName = 'field' . $j . '_' . mt_rand(100, 999);
            $compositeKey[$fieldName] = self::generateRandomValue();
        }

        return $compositeKey;
    }

    private static function generateRandomValue(): string|int
    {
        if (mt_rand(0, 1) === 0) {
            return mt_rand(1, 100000);
        }

        $length = mt_rand(1, 20);
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';
        for ($k = 0; $k < $length; $k++) {
            $str .= $chars[mt_rand(0, strlen($chars) - 1)];
        }

        return $str;
    }
}
