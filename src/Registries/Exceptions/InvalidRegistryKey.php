<?php

namespace Rushing\Popcorn\Registries\Exceptions;

use RuntimeException;

/**
 * A string was offered as a {@see \Rushing\Popcorn\Registries\RegistryKey} and is not one.
 *
 * Thrown at PARSE time, never at resolve time — an unparseable key is a declaration error, not a
 * miss, which is why it is deliberately absent from {@see MissReason}. Sibling to
 * {@see RegistryMiss} and {@see DuplicateRegistryKey} under `RuntimeException`, following the
 * per-family-base house pattern (`RunFailed` for the run family); registry-kernel ticket 06
 * confirmed there is no package-wide `PopcornException` to extend, and ticket 13 decides whether
 * one should exist.
 */
class InvalidRegistryKey extends RuntimeException {}
