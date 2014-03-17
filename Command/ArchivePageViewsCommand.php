<?php

namespace AH\Service\ArchivingBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ArchivePageViewsCommand extends ArchiveCommand {

    protected $table2archove = 'page_views';

    protected function configure() {
        parent::configure();

        $this
            ->setName('archive:page-views')
            ->setDescription('Archives table page_views')
        ;

        $this->table_source = 'page_views';
    }
}
