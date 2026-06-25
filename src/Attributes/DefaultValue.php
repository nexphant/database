<?php
namespace Nexphant\Database\Attributes;
use Attribute;
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DefaultValue { public function __construct(public readonly mixed $value = null) {} }
