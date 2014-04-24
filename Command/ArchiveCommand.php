<?php

namespace AH\Service\ArchivingBundle\Command;

use Doctrine\DBAL\Statement;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\TableHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ArchiveCommand extends ContainerAwareCommand {

    protected $em_source = null;
    protected $em_dest = null;
    protected $table_source = null;
    protected $batch_size = null;
    protected $strategy_value = null;
    protected $select_limit = null;
    protected $days = null;
    protected $timeout_cutoff = null;
    protected $date_field = null;

    protected $env = null;

    private $cache_table_existence = array();
    private $cache_current_batch_ids = array();

    protected $output = null;

    protected function configure() {
        $this
            ->setName('archive:generic')
            ->setDescription('Generic Archiver to archive a specified source table using a strategy defined into one of multiple destination table(s)')
            ->addArgument('table_name', InputArgument::REQUIRED, 'The name of the source table to archive')
            ->addOption('days', null,
                InputOption::VALUE_REQUIRED,
                'Defines how many days in the past to start archive. Example, 14 would archive data older than 14 days',
                90)
            ->addOption('strategy-z-tables', null,
                InputOption::VALUE_OPTIONAL,
                'Strategy is to create tables of the same name, in format zTableNameYm (ex zclients' . date('Ym') . '.',
                'Ym')
            ->addOption('strategy-source-field', null,
                InputOption::VALUE_REQUIRED,
                'Field to base the strategy on. For the YYYYMM strategy, this has to be a date field',
                'created_at')
            ->addOption('batch-size', null,
                InputOption::VALUE_OPTIONAL,
                'Size of each batch. It is important not to choose too big or small a batch. Big batches will cause memory problems, small ones will decrease the speed of archiving.',
                100)
            ->addOption('source-entity-manager', null,
                InputOption::VALUE_REQUIRED, 'Source Entity Manager to archive from',
                'default')
            ->addOption('destination-entity-manager', null,
                InputOption::VALUE_REQUIRED,
                'Destination Entity Manager to archive to. If none is provided, the same as --source-entity-manager will be used.',
                'default')
            ->addOption('select-size', null,
                InputOption::VALUE_REQUIRED,
                'The amount of data to select from the source at a time. This is different to batch, this as an example selects 9999 entries by default, and it goes through it in batches of 100 by default',
                9999)
            ->addOption('date-field', null,
                InputOption::VALUE_REQUIRED,
                'Field to use in where clause to evaluate against the "days" field',
                'created_at')
            ->addOption('timeout', null,
                InputOption::VALUE_REQUIRED,
                'The script should not run indefinitely, thus will kill itself after running for a certain amount of time (in seconds)',
                1800);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output) {
        $table1 = $this->getHelperSet()->get('table');
        $table2 = $this->getHelperSet()->get('table');
        /* @var $table TableHelper */

        $table1->setHeaders(array_keys($input->getArguments()))
            ->addRow($input->getArguments())
            ->render($output);

        $table2->setHeaders(array_keys($input->getOptions()))
            ->addRow($input->getoptions())
            ->render($output);

        $this->env = $input->getOption('env');

        if ($this->env != 'prod') {
            $output->writeln('<question>NOTE:</question> Running in <error>DEV</error> mode. Nothing will be done real-world. If you want to run it real-world, run it with "--env=prod"');
        }

        $this->output = $output;

        $this->em_source = $this->getContainer()->get('doctrine')->getManager(
            $input->getOption('source-entity-manager')
        );

        $this->em_dest = $this->em_source;
        if ($input->hasOption('destination-entity-manager')) {
            $this->em_dest = $this->getContainer()->get('doctrine')->getManager(
                $input->getOption('destination-entity-manager')
            );
        }

        $this->batch_size = $input->getOption('batch-size');

        $this->strategy_value = $input->getOption('strategy-z-tables');

        $this->select_limit = $input->getOption('select-size');

        $this->days = $input->getOption('days');

        $this->table_source = $input->getArgument('table_name');

        $this->timeout_cutoff = strtotime('now') + $input->getOption('timeout');

        $this->date_field = $input->getOption('date-field');
    }

    /**
     *
     * @param object|\Symfony\Component\Console\Input\InputInterface $input
     * @param object|\Symfony\Component\Console\Output\OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $sql = 'select * from ' . $this->table_source . ' where '.$this->date_field.' <= date_sub(now(), interval ' . $this->days . ' day) limit ' . $this->select_limit;
        $stmt = $this->em_source->getConnection()->prepare($sql);
        $stmt->execute();

        while (($rowCount = $stmt->rowCount()) > 0) {
            $this->output->writeln('<info>' . $stmt->rowCount() . ' rows selected from '
                . $this->table_source . '. Commencing archiving in batches of ' . $this->batch_size
                . ' [Memory Footprint: ' . round(memory_get_usage() / pow(2, 10), 2) . ' MB]'
                . '</info>');

            $showFirstRow = true;
            while ($row = $stmt->fetch()) {

                // Timeout check:
                if (strtotime('now') > $this->timeout_cutoff) {
                    $output->writeln('<comment>Timeout Reached. Bailing out until next run.</comment>');

                    break 2;
                }

                if ($showFirstRow) {
                    $output->writeln('<info>MinID: '.$row['id'].' MinDATE: '.$row[$this->date_field].'</info>');
                    $showFirstRow = false;
                }

                $table_dest = 'z' . $this->table_source . date($this->strategy_value, strtotime($row[$this->date_field]));
                if (!$this->destinationTableExists($table_dest)) {
                    $this->destinationTableCreate($table_dest);
                }

                $sql = 'replace into `' . $table_dest . '` values (';
                $sql .= ':' . implode(', :', array_keys($row));
                $sql .= ')';

                if ($output->isDebug()) {
                    $output->writeln($sql . ' [' . implode(',', $row) . ']');
                }

                $stmt_replace = $this->em_dest->getConnection()->prepare($sql);

                $this->em_dest->getConnection()->getConfiguration()->setSQLLogger(null);

                if ($this->env == 'prod') {
                    $stmt_replace->execute($row);
                    /* @var $stmt_replace Statement */
                    $stmt_replace->closeCursor();
                }

                $this->cache_current_batch_ids[] = $row['id'];

                // Check if we're busting the batch size, if so delete from source:
                if ($idsCount = count($this->cache_current_batch_ids) >= $this->batch_size) {
                    $this->deleteBatch();
                }
            }

            // Delete what we have done from the source:
            $this->deleteBatch();

            if ($rowCount < $this->select_limit) {
                $this->output->writeln('Row count ('.$rowCount.') smaller than select-limit ('.$this->select_limit.'). We\'re done.');
                break; // get out of the loop, as the last select we did we got less entries than we wanted, which means we're done
            }

            // Execute another one, so we can get more results:
            $stmt->execute();
        }
    }

    private function deleteBatch() {
        if (count($this->cache_current_batch_ids) <= 0) {
            return;
        }

        $this->output->write('Deleting batch of ' . count($this->cache_current_batch_ids) . ' entries... ');

        $sql = 'delete from ' . $this->table_source . ' where id in (' . implode(',', $this->cache_current_batch_ids) . ')';
        $stmt = $this->em_source->getConnection()->prepare($sql);
        if ($this->env == 'prod') {
            $stmt->execute();
        }

        $this->cache_current_batch_ids = array();

        $this->output->writeln('done.');
    }

    /**
     * Checks whether the destination table exists or not.
     *
     * @param $v Table name to check
     * @return bool
     */
    private function destinationTableExists($v) {
        if (!array_key_exists($v, $this->cache_table_existence) || $this->cache_table_existence[$v] !== true) {
            if ($this->output->isVerbose()) {
                $this->output->writeln('<info>Checking if ' . $v . ' exists in destination db.</info>');
            }

            $sql = 'show tables like \'' . $v . '\'';
            $stmt = $this->em_dest->getConnection()->prepare($sql);
            $stmt->execute();

            $this->cache_table_existence[$v] = ($stmt->rowCount() > 0 ? true : false);
        }

        return $this->cache_table_existence[$v];
    }

    /**
     * Creates a carbon copy of the original source table structure into the destination table structure (and db)
     *
     * @param $v Table name to create
     */
    private function destinationTableCreate($v) {
        $sql = 'show create table ' . $this->table_source . '';
        $stmt = $this->em_source->getConnection()->prepare($sql);
        $stmt->execute();

        $result = $stmt->fetch();

        $SQL_CREATE_DB = $result['Create Table'];

        $SQL_CREATE_DB = preg_replace('/CREATE TABLE [`].*[`]/i', 'CREATE TABLE `' . $v . '`', $SQL_CREATE_DB);
        $SQL_CREATE_DB = preg_replace('/ENGINE=ndbcluster/i', 'ENGINE=MyISAM', $SQL_CREATE_DB);
        $SQL_CREATE_DB = preg_replace('/ENGINE=innodb/i', 'ENGINE=MyISAM', $SQL_CREATE_DB);

//        $SQL_CREATE_DB .= " DATA DIRECTORY='/u//var/lib/mysql/afriradius/' INDEX DIRECTORY='/u//var/lib/mysql/afriradius/'";

        // Create the new table:
        if ($this->output->isVerbose()) {
            $this->output->writeln('<comment>Creating table ' . $v . ' in destination db.</comment>');
        }
        $stmt = $this->em_dest->getConnection()->prepare($SQL_CREATE_DB);
        if ($this->env == 'prod') {
            $stmt->execute();
        }

        $this->cache_table_existence[$v] = true;
    }
}
