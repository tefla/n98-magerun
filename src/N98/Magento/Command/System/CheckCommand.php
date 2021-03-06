<?php

namespace N98\Magento\Command\System;

use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckCommand extends AbstractMagentoCommand
{
    /**
     * @var array
     */
    protected $infos;

    /**
     * @var int
     */
    protected $_verificationTimeOut = 30;

    /**
     * Command config
     *
     * @var array
     */
    protected $_config;

    protected function configure()
    {
        $this
            ->setName('sys:check')
            ->setDescription('Checks Magento System');

        $help = <<<HELP
- Checks missing files and folders
- Security
- PHP Extensions (Required and Bytecode Cache)
- MySQL InnoDB Engine
HELP;
        $this->setHelp($help);
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_config = $this->getCommandConfig();
        $this->detectMagento($output);
        if ($this->initMagento($output)) {

            if ($this->_magentoMajorVersion == self::MAGENTO_MAJOR_VERSION_2) {
                $output->writeln("<error>WARNING: Magento 2 requirements are not yet defined. Until then Magento 1 requirements are checked.</error>");
            }

            $this->checkSettings($input, $output);
            $this->checkFilesystem($input, $output);
            $this->checkPhp($input, $output);
            $this->checkSecurity($input, $output);
            $this->checkMysql($input, $output);
        }
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function checkFilesystem($input, $output)
    {
        $this->writeSection($output, 'Check: Filesystem');

        /**
         * Check folders
         */
        $folders = $this->_config['filesystem']['folders'];

        foreach ($folders as $folder => $comment) {
            if (file_exists($this->_magentoRootFolder . DIRECTORY_SEPARATOR . $folder)) {
                $output->writeln("<info>Folder <comment>" . $folder . "</comment> found.</info>");
                if (!is_writeable($this->_magentoRootFolder . DIRECTORY_SEPARATOR . $folder)) {
                    $output->writeln("<error>Folder " . $folder . " is not writeable!</error><comment> Usage: " . $comment . "</comment>");
                }
            } else {
                $output->writeln("<error>Folder " . $folder . " not found!</error><comment> Usage: " . $comment . "</comment>");
            }
        }

        /**
         * Check files
         */
        $files = $folders = $this->_config['filesystem']['files'];

        foreach ($files as $file => $comment) {
            if (file_exists($this->_magentoRootFolder . DIRECTORY_SEPARATOR . $file)) {
                $output->writeln("<info>File <comment>" . $file . "</comment> found.</info>");
            } else {
                $output->writeln("<error>File " . $file . " not found!</error><comment> Usage: " . $comment . "</comment>");
            }
        }
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function checkPhp($input, $output)
    {
        $this->writeSection($output, 'Check: PHP');

        $requiredExtensions = $this->_config['php']['required-extensions'];

        foreach ($requiredExtensions as $ext) {
            if (extension_loaded($ext)) {
                $output->writeln("<info>Required PHP Module <comment>$ext</comment> found.</info>");
            } else {
                $output->writeln("<error>Required PHP Module $ext not found!</error>");
            }
        }

        /**
         * Check Bytecode Cache
         */
        $bytecopdeCacheExtensions = $this->_config['php']['bytecode-cache-extensions'];
        $bytecodeCacheExtensionLoaded = false;
        $bytecodeCacheExtension = null;
        foreach ($bytecopdeCacheExtensions as $ext) {
            if (extension_loaded($ext)) {
                $bytecodeCacheExtension = $ext;
                $bytecodeCacheExtensionLoaded = true;
                break;
            }
        }
        if ($bytecodeCacheExtensionLoaded) {
            $output->writeln("<info>Bytecode Cache <comment>$bytecodeCacheExtension</comment> found.</info>");
        } else {
            $output->writeln("<error>No Bytecode-Cache found!</error> <comment>It's recommended to install anyone of " . implode(', ', $bytecopdeCacheExtensions) . ".</comment>");
        }
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function checkSecurity($input, $output)
    {
        $this->writeSection($output, 'Check: Security');

        $filePath = 'app/etc/local.xml';
        $defaultUnsecureBaseURL = (string) \Mage::getConfig()->getNode('default/' . \Mage_Core_Model_Store::XML_PATH_UNSECURE_BASE_URL);

        $http = new \Varien_Http_Adapter_Curl();
        $http->setConfig(array('timeout' => $this->_verificationTimeOut));
        $http->write(\Zend_Http_Client::POST, $defaultUnsecureBaseURL . $filePath);
        $responseBody = $http->read();
        $responseCode = \Zend_Http_Response::extractCode($responseBody);
        $http->close();

        if ($responseCode === 200) {
            $output->writeln("<error>$filePath can be accessed from outside!</error>");
        } else {
            $output->writeln("<info><comment>$filePath</comment> cannot be accessed from outside.</info>");
        }
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function checkMysql($input, $output)
    {
        $this->writeSection($output, 'Check: MySQL');

        $dbAdapter = $this->_getModel('core/resource', 'Mage_Core_Model_Resource')->getConnection('core_write');

        /**
         * Check Version
         */
        $mysqlVersion = $dbAdapter->fetchOne('SELECT VERSION()');
        if (version_compare($mysqlVersion, '4.1.20', '>=')) {
            $output->writeln("<info>MySQL Version <comment>$mysqlVersion</comment> found.</info>");
        } else {
            $output->writeln("<error>MySQL Version $mysqlVersion found. Upgrade your MySQL Version.</error>");
        }

            /**
         * Check Engines
         */
        $engines = $dbAdapter->fetchAll('SHOW ENGINES');
        $innodbFound = false;
        foreach ($engines as $engine) {
            if (strtolower($engine['Engine']) == 'innodb') {
                $innodbFound = true;
                break;
            }
        }

        if ($innodbFound) {
            $output->writeln("<info>Required MySQL Storage Engine <comment>InnoDB</comment> found.</info>");
        } else {
            $output->writeln("<error>Required MySQL Storage Engine \"InnoDB\" not found!</error>");
        }
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function checkSettings($input, $output)
    {
        $this->writeSection($output, 'Check: Settings');
        $this->checkSettingsBaseUrl($output);
        $this->checkSettingsCookie($output);
    }

    /**
     * Check cookie domain
     *
     * @param $output
     */
    protected function checkSettingsCookie($output)
    {
        $check = function($value, $store) {
            $cookieDomain = \Mage::getStoreConfig('web/cookie/cookie_domain', $store);

            $ok = true;
            if (!empty($cookieDomain)) {
                $ok = strpos(parse_url($value, PHP_URL_HOST), $cookieDomain);
            }

            return $ok;
        };

        $this->_checkSetting(
            $output,
            'Cookie Domain (unsecure)',
            'web/unsecure/base_url',
            'Cookie Domain and Unsecure BaseURL (http) does not match',
            $check
        );

        $this->_checkSetting(
            $output,
            'Cookie Domain (secure)',
            'web/secure/base_url',
            'Cookie Domain and Secure BaseURL (https) does not match',
            $check
        );
    }

    /**
     * @param $output
     */
    protected function checkSettingsBaseUrl($output)
    {
        $errorMessage = 'localhost should not be used as hostname. <info>Hostname must contain a dot</info>';
        $check = function($value, $store) {
            return parse_url($value, PHP_URL_HOST) !== 'localhost';
        };

        $this->_checkSetting($output, 'Unsecure BaseURL', 'web/unsecure/base_url', $errorMessage, $check);
        $this->_checkSetting($output, 'Secure BaseURL', 'web/secure/base_url', $errorMessage, $check);
    }

    /**
     * @param $output
     * @param $configPath
     * @param \Closure $check
     * @param $errorMessage
     * @param $checkType
     */
    protected function _checkSetting($output, $checkType, $configPath, $errorMessage, \Closure $check)
    {
        $errors = 0;
        foreach (\Mage::app()->getStores() as $store) {
            $configValue = \Mage::getStoreConfig($configPath, $store);
            if (!$check($configValue, $store)) {
                $output->writeln(
                    '<error><comment>Store: ' . $store->getCode() . '</comment> ' . $errorMessage . '</error>'
                );
                $errors++;
            }
        }
        if ($errors === 0) {
            $output->writeln('<comment>' . $checkType . '</comment> <info>OK</info>');
        }
    }
}
