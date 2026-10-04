<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('app:health', function (): void {
    $this->comment('CreatorSMP4 application is healthy.');
})->purpose('Check the application bootstrap');
