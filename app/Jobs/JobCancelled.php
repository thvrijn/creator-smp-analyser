<?php

namespace App\Jobs;

use RuntimeException;

/** Thrown inside a job when its run was cancelled in the UI; the job catches it and stops without failing. */
class JobCancelled extends RuntimeException {}
