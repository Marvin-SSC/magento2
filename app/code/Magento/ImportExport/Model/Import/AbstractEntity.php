<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ImportExport\Model\Import;

use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingError;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;

/**
 * Import entity abstract model
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractEntity
{
    /**
     * Custom row import behavior column name
     */
    const COLUMN_ACTION = '_action';

    /**
     * Value in custom column for delete behaviour
     */
    const COLUMN_ACTION_VALUE_DELETE = 'delete';

    /**#@+
     * XML paths to parameters
     */
    const XML_PATH_BUNCH_SIZE = 'import/format_v2/bunch_size';

    const XML_PATH_PAGE_SIZE = 'import/format_v2/page_size';

    /**#@-*/

    /**#@+
     * Database constants
     */
    const DB_MAX_VARCHAR_LENGTH = 256;

    const DB_MAX_TEXT_LENGTH = 65536;

    const ERROR_CODE_SYSTEM_EXCEPTION = 'systemException';
    const ERROR_CODE_COLUMN_NOT_FOUND = 'columnNotFound';
    const ERROR_CODE_COLUMN_EMPTY_HEADER = 'columnEmptyHeader';
    const ERROR_CODE_COLUMN_NAME_INVALID = 'columnNameInvalid';
    const ERROR_CODE_ATTRIBUTE_NOT_VALID = 'attributeNotInvalid';
    const ERROR_CODE_DUPLICATE_UNIQUE_ATTRIBUTE = 'duplicateUniqueAttribute';

    protected $errorMessageTemplates = [
        self::ERROR_CODE_SYSTEM_EXCEPTION => 'General system exception happened',
        self::ERROR_CODE_COLUMN_NOT_FOUND => 'We can\'t find required columns: %1.',
        self::ERROR_CODE_COLUMN_EMPTY_HEADER => 'Columns number: "%1" have empty headers',
        self::ERROR_CODE_COLUMN_NAME_INVALID => 'Column names: "%1" are invalid',
        self::ERROR_CODE_ATTRIBUTE_NOT_VALID => "Please correct the value for '%s'.",
        self::ERROR_CODE_DUPLICATE_UNIQUE_ATTRIBUTE => "Duplicate Unique Attribute for '%s'",
    ];

    /**#@-*/

    /**
     * DB connection
     *
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $_connection;

    /**
     * Has data process validation done?
     *
     * @var bool
     */
    protected $_dataValidated = false;

    /**
     * DB data source model
     *
     * @var \Magento\ImportExport\Model\Resource\Import\Data
     */
    protected $_dataSourceModel;

    /**
     * @var ProcessingErrorAggregatorInterface
     */
    protected $errorAggregator;

    /**
     * Flag to disable import
     *
     * @var bool
     */
    protected $_importAllowed = true;

    /**
     * Magento string lib
     *
     * @var \Magento\Framework\Stdlib\String
     */
    protected $string;

    /**
     * Entity model parameters
     *
     * @var array
     */
    protected $_parameters = [];

    /**
     * Column names that holds values with particular meaning
     *
     * @var string[]
     */
    protected $_specialAttributes = [self::COLUMN_ACTION];

    /**
     * Permanent entity columns
     *
     * @var string[]
     */
    protected $_permanentAttributes = [];

    /**
     * Number of entities processed by validation
     *
     * @var int
     */
    protected $_processedEntitiesCount = 0;

    /**
     * Number of rows processed by validation
     *
     * @var int
     */
    protected $_processedRowsCount = 0;

    /**
     * Need to log in import history
     *
     * @var bool
     */
    protected $logInHistory = false;

    /**
     * Rows which will be skipped during import
     *
     * [Row number 1] => true,
     * ...
     * [Row number N] => true
     *
     * @var array
     */
    protected $_skippedRows = [];

    /**
     * Array of numbers of validated rows as keys and boolean TRUE as values
     *
     * @var array
     */
    protected $_validatedRows = [];

    /**
     * Source model
     *
     * @var AbstractSource
     */
    protected $_source;

    /**
     * Array of unique attributes
     *
     * @var array
     */
    protected $_uniqueAttributes = [];

    /**
     * List of available behaviors
     *
     * @var string[]
     */
    protected $_availableBehaviors = [
        \Magento\ImportExport\Model\Import::BEHAVIOR_ADD_UPDATE,
        \Magento\ImportExport\Model\Import::BEHAVIOR_DELETE,
        \Magento\ImportExport\Model\Import::BEHAVIOR_CUSTOM,
    ];

    /**
     * Number of items to fetch from db in one query
     *
     * @var int
     */
    protected $_pageSize;

    /**
     * Maximum size of packet, that can be sent to DB
     *
     * @var int
     */
    protected $_maxDataSize;

    /**
     * Number of items to save to the db in one query
     *
     * @var int
     */
    protected $_bunchSize;

    /**
     * Code of a primary attribute which identifies the entity group if import contains of multiple rows
     *
     * @var string
     */
    protected $masterAttributeCode;

    /**
     * Core store config
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @param \Magento\Framework\Stdlib\String $string
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\ImportExport\Model\ImportFactory $importFactory
     * @param \Magento\ImportExport\Model\Resource\Helper $resourceHelper
     * @param \Magento\Framework\App\Resource $resource
     * @param ProcessingErrorAggregatorInterface $errorAggregator
     * @param array $data
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function __construct(
        \Magento\Framework\Stdlib\String $string,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\ImportExport\Model\ImportFactory $importFactory,
        \Magento\ImportExport\Model\Resource\Helper $resourceHelper,
        \Magento\Framework\App\Resource $resource,
        ProcessingErrorAggregatorInterface $errorAggregator,
        array $data = []
    ) {
        $this->_scopeConfig = $scopeConfig;
        $this->_dataSourceModel = isset(
            $data['data_source_model']
        ) ? $data['data_source_model'] : $importFactory->create()->getDataSourceModel();
        $this->_connection = isset($data['connection']) ? $data['connection'] : $resource->getConnection('write');
        $this->string = $string;
        $this->_pageSize = isset(
            $data['page_size']
        ) ? $data['page_size'] : (static::XML_PATH_PAGE_SIZE ? (int)$this->_scopeConfig->getValue(
            static::XML_PATH_PAGE_SIZE,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ) : 0);
        $this->_maxDataSize = isset(
            $data['max_data_size']
        ) ? $data['max_data_size'] : $resourceHelper->getMaxDataSize();
        $this->_bunchSize = isset(
            $data['bunch_size']
        ) ? $data['bunch_size'] : (static::XML_PATH_BUNCH_SIZE ? (int)$this->_scopeConfig->getValue(
            static::XML_PATH_BUNCH_SIZE,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ) : 0);

        $this->errorAggregator = $errorAggregator;

        foreach ($this->errorMessageTemplates as $errorCode => $message) {
            $this->errorAggregator->addErrorMessageTemplate($errorCode, $message);
        }
    }

    /**
     * Import data rows
     *
     * @abstract
     * @return boolean
     */
    abstract protected function _importData();

    /**
     * Imported entity type code getter
     *
     * @abstract
     * @return string
     */
    abstract public function getEntityTypeCode();

    /**
     * Change row data before saving in DB table
     *
     * @param array $rowData
     * @return array
     */
    protected function _prepareRowForDb(array $rowData)
    {
        /**
         * Convert all empty strings to null values, as
         * a) we don't use empty string in DB
         * b) empty strings instead of numeric values will product errors in Sql Server
         */
        foreach ($rowData as $key => $val) {
            if ($val === '') {
                $rowData[$key] = null;
            }
        }
        return $rowData;
    }

    /**
     * Validate data rows and save bunches to DB
     *
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _saveValidatedBunches()
    {
        $source = $this->getSource();
        $bunchRows = [];
        $startNewBunch = false;

        $source->rewind();
        $this->_dataSourceModel->cleanBunches();
        $masterAttributeCode = $this->getMasterAttributeCode();

        while ($source->valid() || count($bunchRows) || isset($entityGroup)) {
            if ($startNewBunch || !$source->valid()) {
                /* If the end approached add last validated entity group to the bunch */
                if (!$source->valid() && isset($entityGroup)) {
                    foreach ($entityGroup as $key => $value) {
                        $bunchRows[$key] = $value;
                    }
                    unset($entityGroup);
                }
                $this->_dataSourceModel->saveBunch($this->getEntityTypeCode(), $this->getBehavior(), $bunchRows);

                $bunchRows = [];
                $startNewBunch = false;
            }
            if ($source->valid()) {
                $rowData = $source->current();

                if (isset($rowData[$masterAttributeCode]) && trim($rowData[$masterAttributeCode])) {
                    /* Add entity group that passed validation to bunch */
                    if (isset($entityGroup)) {
                        foreach ($entityGroup as $key => $value) {
                            $bunchRows[$key] = $value;
                        }
                        $productDataSize = strlen(serialize($bunchRows));

                        /* Check if the new bunch should be started */
                        $isBunchSizeExceeded = ($this->_bunchSize > 0 && count($bunchRows) >= $this->_bunchSize);
                        $startNewBunch = $productDataSize >= $this->_maxDataSize || $isBunchSizeExceeded;
                    }

                    /* And start a new one */
                    $entityGroup = [];
                }

                if (isset($entityGroup) && $this->validateRow($rowData, $source->key())) {
                    /* Add row to entity group */
                    $entityGroup[$source->key()] = $this->_prepareRowForDb($rowData);
                } elseif (isset($entityGroup)) {
                    /* In case validation of one line of the group fails kill the entire group */
                    unset($entityGroup);
                }

                $this->_processedRowsCount++;
                $source->next();
            }
        }
        return $this;
    }

    /**
     * Add error with corresponding current data source row number.
     *
     * @param string $errorCode Error code or simply column name
     * @param int $errorRowNum Row number.
     * @param string $colName OPTIONAL Column name.
     * @param string $errorMessage OPTIONAL Column name.
     * @param string $errorLevel
     * @param string $errorDescription
     * @return $this
     */
    public function addRowError(
        $errorCode,
        $errorRowNum,
        $colName = null,
        $errorMessage = null,
        $errorLevel = ProcessingError::ERROR_LEVEL_CRITICAL,
        $errorDescription = null
    ) {
        $errorCode = (string)$errorCode;
        $this->errorAggregator->addError(
            $errorCode,
            $errorLevel,
            $errorRowNum,
            $colName,
            $errorMessage,
            $errorDescription
        );

        return $this;
    }

    /**
     * Add message template for specific error code from outside
     *
     * @param string $errorCode Error code
     * @param string $message Message template
     * @return $this
     */
    public function addMessageTemplate($errorCode, $message)
    {
        $this->errorAggregator->addErrorMessageTemplate($errorCode, $message);

        return $this;
    }

    /**
     * Import behavior getter
     *
     * @param array $rowData
     * @return string
     */
    public function getBehavior(array $rowData = null)
    {
        if (isset(
            $this->_parameters['behavior']
        ) && in_array(
            $this->_parameters['behavior'],
            $this->_availableBehaviors
        )
        ) {
            $behavior = $this->_parameters['behavior'];
            if ($rowData !== null && $behavior == \Magento\ImportExport\Model\Import::BEHAVIOR_CUSTOM) {
                // try analyze value in self::COLUMN_CUSTOM column and return behavior for given $rowData
                if (array_key_exists(self::COLUMN_ACTION, $rowData)) {
                    if (strtolower($rowData[self::COLUMN_ACTION]) == self::COLUMN_ACTION_VALUE_DELETE) {
                        $behavior = \Magento\ImportExport\Model\Import::BEHAVIOR_DELETE;
                    } else {
                        // as per task description, if column value is different to self::COLUMN_CUSTOM_VALUE_DELETE,
                        // we should always use default behavior
                        return self::getDefaultBehavior();
                    }
                    if (in_array($behavior, $this->_availableBehaviors)) {
                        return $behavior;
                    }
                }
            } else {
                // if method is invoked without $rowData we should just return $this->_parameters['behavior']
                return $behavior;
            }
        }

        return self::getDefaultBehavior();
    }

    /**
     * Get default import behavior
     *
     * @return string
     */
    public static function getDefaultBehavior()
    {
        return \Magento\ImportExport\Model\Import::BEHAVIOR_ADD_UPDATE;
    }

    /**
     * Returns number of checked entities
     *
     * @return int
     */
    public function getProcessedEntitiesCount()
    {
        return $this->_processedEntitiesCount;
    }

    /**
     * Returns number of checked rows
     *
     * @return int
     */
    public function getProcessedRowsCount()
    {
        return $this->_processedRowsCount;
    }

    /**
     * Source object getter
     *
     * @return AbstractSource
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getSource()
    {
        if (!$this->_source) {
            throw new \Magento\Framework\Exception\LocalizedException(__('The source is not set.'));
        }
        return $this->_source;
    }

    /**
     * Import process start
     *
     * @return bool Result of operation
     */
    public function importData()
    {
        return $this->_importData();
    }

    /**
     * Is attribute contains particular data (not plain entity attribute)
     *
     * @param string $attributeCode
     * @return bool
     */
    public function isAttributeParticular($attributeCode)
    {
        return in_array($attributeCode, $this->_specialAttributes);
    }

    /**
     * @return string the master attribute code to use in an import
     */
    public function getMasterAttributeCode()
    {
        return $this->masterAttributeCode;
    }

    /**
     * Check one attribute can be overridden in child
     *
     * @param string $attributeCode Attribute code
     * @param array $attributeParams Attribute params
     * @param array $rowData Row data
     * @param int $rowNumber
     * @return bool
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function isAttributeValid($attributeCode, array $attributeParams, array $rowData, $rowNumber)
    {
        switch ($attributeParams['type']) {
            case 'varchar':
                $value = $this->string->cleanString($rowData[$attributeCode]);
                $valid = $this->string->strlen($value) < self::DB_MAX_VARCHAR_LENGTH;
                break;
            case 'decimal':
                $value = trim($rowData[$attributeCode]);
                $valid = (double)$value == $value && is_numeric($value);
                break;
            case 'select':
            case 'multiselect':
                $valid = isset($attributeParams['options'][strtolower($rowData[$attributeCode])]);
                break;
            case 'int':
                $value = trim($rowData[$attributeCode]);
                $valid = (int)$value == $value && is_numeric($value);
                break;
            case 'datetime':
                $value = trim($rowData[$attributeCode]);
                $valid = strtotime($value) !== false;
                break;
            case 'text':
                $value = $this->string->cleanString($rowData[$attributeCode]);
                $valid = $this->string->strlen($value) < self::DB_MAX_TEXT_LENGTH;
                break;
            default:
                $valid = true;
                break;
        }

        if (!$valid) {
            $this->addRowError(self::ERROR_CODE_ATTRIBUTE_NOT_VALID, $rowNumber, $attributeCode);
        } elseif (!empty($attributeParams['is_unique'])) {
            if (isset($this->_uniqueAttributes[$attributeCode][$rowData[$attributeCode]])) {
                $this->addRowError(self::ERROR_CODE_DUPLICATE_UNIQUE_ATTRIBUTE, $rowNumber, $attributeCode);
                return false;
            }
            $this->_uniqueAttributes[$attributeCode][$rowData[$attributeCode]] = true;
        }
        return (bool)$valid;
    }

    /**
     * Import possibility getter
     *
     * @return bool
     */
    public function isImportAllowed()
    {
        return $this->_importAllowed;
    }

    /**
     * Returns TRUE if row is valid and not in skipped rows array
     *
     * @param array $rowData
     * @param int $rowNumber
     * @return bool
     */
    public function isRowAllowedToImport(array $rowData, $rowNumber)
    {
        return $this->validateRow($rowData, $rowNumber) && !isset($this->_skippedRows[$rowNumber]);
    }

    /**
     * Is import need to log in history.
     *
     * @return bool
     */
    public function isNeedToLogInHistory()
    {
        return $this->logInHistory;
    }

    /**
     * Validate data row
     *
     * @param array $rowData
     * @param int $rowNumber
     * @return bool
     */
    abstract public function validateRow(array $rowData, $rowNumber);

    /**
     * Set data from outside to change behavior
     *
     * @param array $parameters
     * @return $this
     */
    public function setParameters(array $parameters)
    {
        $this->_parameters = $parameters;
        return $this;
    }

    /**
     * Source model setter
     *
     * @param AbstractSource $source
     * @return $this
     */
    public function setSource(AbstractSource $source)
    {
        $this->_source = $source;
        $this->_dataValidated = false;

        return $this;
    }

    /**
     * Validate data
     *
     * @return ProcessingErrorAggregatorInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function validateData()
    {
        if (!$this->_dataValidated) {
            $this->errorAggregator->clear();
            // do all permanent columns exist?
            $absentColumns = array_diff($this->_permanentAttributes, $this->getSource()->getColNames());
            if ($absentColumns) {
                $this->errorAggregator->addError(
                    self::ERROR_CODE_COLUMN_NOT_FOUND,
                    ProcessingError::ERROR_LEVEL_CRITICAL,
                    null,
                    implode(', ', $absentColumns)
                );
            }

            // check attribute columns names validity
            $columnNumber = 0;
            $emptyHeaderColumns = [];
            $invalidColumns = [];
            foreach ($this->getSource()->getColNames() as $columnName) {
                $columnNumber++;
                if (!$this->isAttributeParticular($columnName)) {
                    if (trim($columnName) == '') {
                        $emptyHeaderColumns[] = $columnNumber;
                    } elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $columnName)) {
                        $invalidColumns[] = $columnName;
                    }
                }
            }

            if ($emptyHeaderColumns) {
                $this->errorAggregator->addError(
                    self::ERROR_CODE_COLUMN_EMPTY_HEADER,
                    ProcessingError::ERROR_LEVEL_CRITICAL,
                    null,
                    implode('", "', $emptyHeaderColumns)
                );
            }
            if ($invalidColumns) {
                $this->errorAggregator->addError(
                    self::ERROR_CODE_COLUMN_NAME_INVALID,
                    ProcessingError::ERROR_LEVEL_CRITICAL,
                    null,
                    $invalidColumns
                );
            }

            if (!$this->errorAggregator->getErrorsCount()) {
                $this->_saveValidatedBunches();
                $this->_dataValidated = true;
            }
        }
        return $this->errorAggregator;
    }
}
