<?php

namespace Opcodes\Spike\Exceptions;

use Exception;

/**
 * Thrown when an operation would cause over-allocation of licenses.
 * For example, when releasing a CM Instance would leave bundled agent seats over-allocated.
 */
class OverAllocationException extends Exception
{
    //
}

