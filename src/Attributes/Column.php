<?php
namespace Nexphant\Database\Attributes;
use Attribute;
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column { public function __construct(public readonly string $name = '') {} }
