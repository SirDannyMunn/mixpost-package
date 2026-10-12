<?php

namespace Inovector\Mixpost\Commands;

use Illuminate\Console\Command;
use Inovector\Mixpost\Services\Automations\AutomationRunner;

class RunAutomations extends Command
{
    protected $signature = 'mixpost:run-automations';
    protected $description = 'Process due evergreen, RSS, and engagement follow-up rules';

    public function handle(AutomationRunner $runner): int
    {
        $this->info('Processed '.$runner->due().' due automation rules.');
        return self::SUCCESS;
    }
}
