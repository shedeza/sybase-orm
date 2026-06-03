<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Marks a class as an embeddable value object.
 *
 * Embeddable classes are not entities — they don't have their own table or identity.
 * Instead, their properties are mapped as columns in the parent entity's table,
 * prefixed with the property name.
 *
 * Example:
 *     #[Embeddable]
 *     class Address {
 *         #[Column(type: 'string')]
 *         public string $street = '';
 *
 *         #[Column(type: 'string')]
 *         public string $city = '';
 *     }
 *
 *     #[Entity(table: 'users')]
 *     class User {
 *         #[Embedded(class: Address::class)]
 *         private Address $address;
 *     }
 *
 * This maps to columns: address_street, address_city in the users table.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Embeddable
{
}
