<?php
declare(strict_types=1);

namespace Kudos\ImageSync\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncImages extends Command
{
    // const NAME_ARGUMENT = "name";
    // const NAME_OPTION = "option";
    protected $_syncImages;
    protected $_state;

    /**
     * @param \Kudos\ImageSync\Cron\SyncImages $syncImages
     * @param \Magento\Framework\App\State $state
     */
    public function __construct(
        \Kudos\ImageSync\Cron\SyncImages $syncImages,
        \Magento\Framework\App\State $state
    ) {
        $this->_syncImages = $syncImages;
        $this->_state = $state;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $this->_state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        $this->_syncImages->execute();
    }


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName("kudos_imagesync:syncimages");
        $this->setDescription("Force execute sync images process");
        $this->setDefinition([
            // new InputArgument(self::NAME_ARGUMENT, InputArgument::OPTIONAL, "Name"),
            // new InputOption(self::NAME_OPTION, "-a", InputOption::VALUE_NONE, "Option functionality")
        ]);
        parent::configure();
    }
}

