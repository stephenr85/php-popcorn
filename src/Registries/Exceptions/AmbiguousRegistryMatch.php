<?php

namespace Rushing\Popcorn\Registries\Exceptions;

/**
 * Several entries matched and none of them is the answer. Construct via
 * {@see RegistryMiss::ambiguous()}.
 *
 * A SUBCLASS of {@see RegistryMiss} rather than a sibling, because the relationship is real and
 * Spring models it the same way (`NoUniqueBeanDefinitionException extends
 * NoSuchBeanDefinitionException`): a caller that only wants "I didn't get my entry" catches the
 * parent, and a caller that can do something about the ambiguity — name one exactly, fix the
 * duplicate registration — catches this. Flattening the two would force every call site to inspect
 * a reason code to tell a typo from a collision.
 *
 * Only ever thrown under `PickOne`. Under `ComposeMany` and `RunAll`, several matches are the
 * answer rather than the error (registry-kernel ticket 06, rule 2).
 */
class AmbiguousRegistryMatch extends RegistryMiss {}
