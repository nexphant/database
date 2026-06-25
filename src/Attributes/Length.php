<?php
namespace Nexphant\Database\Attributes;
use Attribute;
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Length { public function __construct(public readonly int $min = 0, public readonly int $max = 0) {} }
