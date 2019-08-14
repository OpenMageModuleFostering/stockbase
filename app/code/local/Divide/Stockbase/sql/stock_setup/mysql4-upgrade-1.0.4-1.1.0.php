<?php
/**
 * Created by PhpStorm.
 * User: nav.appaiya
 * Date: 14-10-2016
 * Time: 16:55
 */
// Set data:
$attributeGroup = 'General';
$attributeSetIds = array(4);

$attributeName  = 'Stockbase Product'; // Is Stockbase product => stockbase_product
$attributeCode  = 'stockbase_product'; // Label stockbase product

$attributeNoosName = 'Stockbase NOOS'; // Is NOOS => stockbase_noos
$attributeNoosCode = 'stockbase_noos'; // Noos Label

$attributeStockName = 'Stockbase Stock'; // Stocklevel stockbase => stockbase_stock
$attributeStockCode = 'stockbase_stock'; // label stocklevel

$attributeEANName = 'Stockbase EAN'; // Stocklevel stockbase => stockbase_stock
$attributeEANCode = 'stockbase_ean'; // label stocklevel

// Configuration for stockbase_product:
$data = array(
    'type'      => 'int',       // Attribute type
    'input'     => 'boolean',          // Input type
    'global'    => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,    // Attribute scope
    'required'  => false,           // Is this attribute required?
    'user_defined' => false,
    'searchable' => false,
    'filterable' => false,
    'comparable' => false,
    'visible_on_front' => false,
    'unique' => false,
    'used_in_product_listing' => true,
    // Filled from above:
    'label' => $attributeName,
);

// Configuration for stockbase noos
$dataNoos = array(
    'type'      => 'int',       // Attribute type
    'input'     => 'boolean',          // Input type
    'global'    => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,    // Attribute scope
    'required'  => false,           // Is this attribute required?
    'user_defined' => false,
    'searchable' => false,
    'filterable' => false,
    'comparable' => false,
    'visible_on_front' => false,
    'unique' => false,
    'used_in_product_listing' => true,
    // Filled from above:
    'label' => $attributeNoosName,
);

// Configuration for stockbase stock
$dataStock = array(
    'type'      => 'text',       // Attribute type
    'input'     => 'text',          // Input type
    'global'    => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,    // Attribute scope
    'required'  => false,           // Is this attribute required?
    'user_defined' => false,
    'searchable' => false,
    'filterable' => false,
    'comparable' => false,
    'visible_on_front' => false,
    'unique' => false,
    'used_in_product_listing' => true,
    // Filled from above:
    'label' => $attributeStockName,
);

// Configuration for stockbase stock
$dataEAN = array(
    'type'      => 'text',       // Attribute type
    'input'     => 'text',          // Input type
    'global'    => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,    // Attribute scope
    'required'  => false,           // Is this attribute required?
    'user_defined' => false,
    'searchable' => false,
    'filterable' => false,
    'comparable' => false,
    'visible_on_front' => false,
    'unique' => false,
    'used_in_product_listing' => true,
    // Filled from above:
    'label' => $attributeEANName,
);

$installer = Mage::getResourceModel('catalog/setup', 'catalog_setup');
$installer->startSetup();
$installer->addAttribute('catalog_product', $attributeCode, $data);
$installer->addAttribute('catalog_product', $attributeNoosCode, $dataNoos);
$installer->addAttribute('catalog_product', $attributeStockCode, $dataStock);
$installer->addAttribute('catalog_product', $attributeEANCode, $dataEAN);

// Add the attribute to the proper sets/groups:
foreach ($attributeSetIds as $attributeSetId) {
    $installer->addAttributeToGroup('catalog_product', $attributeSetId, $attributeGroup, $attributeCode);
    $installer->addAttributeToGroup('catalog_product', $attributeSetId, $attributeGroup, $attributeNoosCode);
    $installer->addAttributeToGroup('catalog_product', $attributeSetId, $attributeGroup, $attributeStockCode);
    $installer->addAttributeToGroup('catalog_product', $attributeSetId, $attributeGroup, $attributeEANCode);
}

// Done:
$installer->endSetup();
