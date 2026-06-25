<?php
namespace Nexphant\Database\Attributes;
use Attribute;
#[Attribute(Attribute::TARGET_CLASS)]
final class Table { public function __construct(public readonly string $name) {} }
