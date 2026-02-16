<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Presets;

use Exception;
use PDO;
use Pterodactyl\Models\Database;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Extensions\SqlDynDatabaseConnection;
use Pterodactyl\Exceptions\Service\Database\DatabaseImportException;

class DatabasePresetsService
{
    public function __construct(
        protected SqlDynDatabaseConnection $dynamic
    ) {
    }

    public function getAvailablePresets(): array
    {
        return [
            'user_management' => [
                'name' => 'User Management System',
                'description' => 'Complete user registration and authentication system',
                'category' => 'Authentication',
                'icon' => 'users',
                'tables' => $this->getUserManagementTables()
            ],
            'ecommerce_basic' => [
                'name' => 'E-commerce Basic',
                'description' => 'Basic e-commerce tables for products and orders',
                'category' => 'E-commerce',
                'icon' => 'shopping-cart',
                'tables' => $this->getEcommerceBasicTables()
            ],
            'blog_system' => [
                'name' => 'Blog System',
                'description' => 'Blog posts, categories, and comments',
                'category' => 'Content',
                'icon' => 'blog',
                'tables' => $this->getBlogSystemTables()
            ],
            'inventory_management' => [
                'name' => 'Inventory Management',
                'description' => 'Product inventory and stock tracking',
                'category' => 'Business',
                'icon' => 'boxes',
                'tables' => $this->getInventoryManagementTables()
            ],
            'pricing_tiers' => [
                'name' => 'Pricing & Subscriptions',
                'description' => 'Pricing plans and subscription management',
                'category' => 'Business',
                'icon' => 'credit-card',
                'tables' => $this->getPricingTiersTables()
            ],
            'cms_basic' => [
                'name' => 'CMS Basic',
                'description' => 'Content management system with pages and media',
                'category' => 'Content',
                'icon' => 'file-alt',
                'tables' => $this->getCMSBasicTables()
            ]
        ];
    }

    public function applyPresets(Database $database, array $presetIds, array $selectedTables = []): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $results = [];
            $allPresets = $this->getAvailablePresets();
            
            foreach ($presetIds as $presetId) {
                if (!isset($allPresets[$presetId])) {
                    continue;
                }
                
                $preset = $allPresets[$presetId];
                $tablesToCreate = empty($selectedTables[$presetId]) 
                    ? array_keys($preset['tables'])
                    : $selectedTables[$presetId];
                
                $presetResults = $this->createPresetTables($connection, $preset, $tablesToCreate);
                $results[$presetId] = $presetResults;
            }
            
            return [
                'success' => true,
                'results' => $results
            ];
            
        } catch (Exception $e) {
            Log::error('Failed to apply database presets: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function createPresetTables(PDO $connection, array $preset, array $tablesToCreate): array
    {
        $results = [];
        
        foreach ($tablesToCreate as $tableName) {
            if (!isset($preset['tables'][$tableName])) {
                continue;
            }
            
            try {
                $tableDefinition = $preset['tables'][$tableName];
                $sql = $this->generateCreateTableSQL($tableName, $tableDefinition);
                
                $connection->exec($sql);
                
                if (!empty($tableDefinition['sample_data'])) {
                    $this->insertSampleData($connection, $tableName, $tableDefinition['sample_data']);
                }
                
                $results[$tableName] = [
                    'success' => true,
                    'message' => 'Table created successfully'
                ];
                
            } catch (Exception $e) {
                $results[$tableName] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    private function generateCreateTableSQL(string $tableName, array $tableDefinition): string
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (\n";
        
        $columnDefinitions = [];
        $primaryKeys = [];
        $indexes = [];
        
        foreach ($tableDefinition['columns'] as $columnName => $column) {
            $columnDef = "  `{$columnName}` {$column['type']}";
            
            if (!empty($column['length'])) {
                $columnDef .= "({$column['length']})";
            }
            
            if (!empty($column['nullable']) && $column['nullable'] === false) {
                $columnDef .= ' NOT NULL';
            }
            
            if (isset($column['default'])) {
                if ($column['default'] === 'CURRENT_TIMESTAMP') {
                    $columnDef .= ' DEFAULT CURRENT_TIMESTAMP';
                } else {
                    $columnDef .= " DEFAULT '{$column['default']}'";
                }
            }
            
            if (!empty($column['auto_increment'])) {
                $columnDef .= ' AUTO_INCREMENT';
            }
            
            if (!empty($column['comment'])) {
                $columnDef .= " COMMENT '{$column['comment']}'";
            }
            
            $columnDefinitions[] = $columnDef;
            
            if (!empty($column['primary'])) {
                $primaryKeys[] = $columnName;
            }
            
            if (!empty($column['index'])) {
                $indexes[] = $columnName;
            }
        }
        
        $sql .= implode(",\n", $columnDefinitions);
        
        if (!empty($primaryKeys)) {
            $sql .= ",\n  PRIMARY KEY (`" . implode('`, `', $primaryKeys) . "`)";
        }
        
        foreach ($indexes as $indexColumn) {
            $sql .= ",\n  INDEX `idx_{$tableName}_{$indexColumn}` (`{$indexColumn}`)";
        }
        
        $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        return $sql;
    }

    private function insertSampleData(PDO $connection, string $tableName, array $sampleData): void
    {
        foreach ($sampleData as $row) {
            $columns = array_keys($row);
            $columnList = '`' . implode('`, `', $columns) . '`';
            $placeholders = ':' . implode(', :', $columns);
            
            $sql = "INSERT INTO `{$tableName}` ({$columnList}) VALUES ({$placeholders})";
            $stmt = $connection->prepare($sql);
            $stmt->execute($row);
        }
    }

    private function getUserManagementTables(): array
    {
        return [
            'users' => [
                'description' => 'Main users table with authentication data',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'uuid' => ['type' => 'CHAR', 'length' => 36, 'nullable' => false, 'index' => true],
                    'username' => ['type' => 'VARCHAR', 'length' => 50, 'nullable' => false, 'index' => true],
                    'email' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false, 'index' => true],
                    'password_hash' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'first_name' => ['type' => 'VARCHAR', 'length' => 100, 'nullable' => true],
                    'last_name' => ['type' => 'VARCHAR', 'length' => 100, 'nullable' => true],
                    'email_verified_at' => ['type' => 'TIMESTAMP', 'nullable' => true],
                    'is_active' => ['type' => 'TINYINT', 'length' => 1, 'default' => '1'],
                    'last_login_at' => ['type' => 'TIMESTAMP', 'nullable' => true],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ],
            'user_profiles' => [
                'description' => 'Extended user profile information',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'user_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'index' => true],
                    'avatar_url' => ['type' => 'VARCHAR', 'length' => 500, 'nullable' => true],
                    'bio' => ['type' => 'TEXT', 'nullable' => true],
                    'phone' => ['type' => 'VARCHAR', 'length' => 20, 'nullable' => true],
                    'address' => ['type' => 'TEXT', 'nullable' => true],
                    'city' => ['type' => 'VARCHAR', 'length' => 100, 'nullable' => true],
                    'country' => ['type' => 'VARCHAR', 'length' => 100, 'nullable' => true],
                    'timezone' => ['type' => 'VARCHAR', 'length' => 50, 'default' => 'UTC'],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ],
            'user_sessions' => [
                'description' => 'User session tracking',
                'columns' => [
                    'id' => ['type' => 'VARCHAR', 'length' => 40, 'nullable' => false, 'primary' => true],
                    'user_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'index' => true],
                    'ip_address' => ['type' => 'VARCHAR', 'length' => 45, 'nullable' => true],
                    'user_agent' => ['type' => 'TEXT', 'nullable' => true],
                    'payload' => ['type' => 'LONGTEXT', 'nullable' => false],
                    'last_activity' => ['type' => 'INT', 'length' => 11, 'nullable' => false, 'index' => true]
                ]
            ]
        ];
    }

    private function getEcommerceBasicTables(): array
    {
        return [
            'products' => [
                'description' => 'Product catalog',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'sku' => ['type' => 'VARCHAR', 'length' => 100, 'nullable' => false, 'index' => true],
                    'name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'description' => ['type' => 'TEXT', 'nullable' => true],
                    'price' => ['type' => 'DECIMAL', 'length' => '10,2', 'nullable' => false],
                    'compare_price' => ['type' => 'DECIMAL', 'length' => '10,2', 'nullable' => true],
                    'cost_price' => ['type' => 'DECIMAL', 'length' => '10,2', 'nullable' => true],
                    'stock_quantity' => ['type' => 'INT', 'length' => 11, 'default' => '0'],
                    'is_active' => ['type' => 'TINYINT', 'length' => 1, 'default' => '1'],
                    'weight' => ['type' => 'DECIMAL', 'length' => '8,3', 'nullable' => true],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ],
                'sample_data' => [
                    ['sku' => 'DEMO-001', 'name' => 'Sample Product', 'description' => 'This is a sample product', 'price' => '29.99', 'stock_quantity' => 100]
                ]
            ],
            'categories' => [
                'description' => 'Product categories',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'parent_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => true, 'index' => true],
                    'name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'slug' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false, 'index' => true],
                    'description' => ['type' => 'TEXT', 'nullable' => true],
                    'is_active' => ['type' => 'TINYINT', 'length' => 1, 'default' => '1'],
                    'sort_order' => ['type' => 'INT', 'length' => 11, 'default' => '0'],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ],
            'orders' => [
                'description' => 'Customer orders',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'order_number' => ['type' => 'VARCHAR', 'length' => 50, 'nullable' => false, 'index' => true],
                    'customer_email' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false, 'index' => true],
                    'customer_name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'status' => ['type' => 'ENUM', 'length' => "'pending','processing','shipped','delivered','cancelled'", 'default' => 'pending'],
                    'subtotal' => ['type' => 'DECIMAL', 'length' => '10,2', 'nullable' => false],
                    'tax_amount' => ['type' => 'DECIMAL', 'length' => '10,2', 'default' => '0.00'],
                    'shipping_amount' => ['type' => 'DECIMAL', 'length' => '10,2', 'default' => '0.00'],
                    'total_amount' => ['type' => 'DECIMAL', 'length' => '10,2', 'nullable' => false],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ]
        ];
    }

    private function getBlogSystemTables(): array
    {
        return [
            'posts' => [
                'description' => 'Blog posts',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'title' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'slug' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false, 'index' => true],
                    'content' => ['type' => 'LONGTEXT', 'nullable' => false],
                    'excerpt' => ['type' => 'TEXT', 'nullable' => true],
                    'author_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'index' => true],
                    'category_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => true, 'index' => true],
                    'featured_image' => ['type' => 'VARCHAR', 'length' => 500, 'nullable' => true],
                    'status' => ['type' => 'ENUM', 'length' => "'draft','published','private'", 'default' => 'draft'],
                    'published_at' => ['type' => 'TIMESTAMP', 'nullable' => true],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ],
            'post_categories' => [
                'description' => 'Blog post categories',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'slug' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false, 'index' => true],
                    'description' => ['type' => 'TEXT', 'nullable' => true],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ],
            'comments' => [
                'description' => 'Post comments',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'post_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'index' => true],
                    'parent_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => true, 'index' => true],
                    'author_name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'author_email' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'content' => ['type' => 'TEXT', 'nullable' => false],
                    'status' => ['type' => 'ENUM', 'length' => "'pending','approved','spam'", 'default' => 'pending'],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ]
        ];
    }

    private function getInventoryManagementTables(): array
    {
        return [
            'inventory_items' => [
                'description' => 'Inventory items tracking',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'sku' => ['type' => 'VARCHAR', 'length' => 100, 'nullable' => false, 'index' => true],
                    'name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'description' => ['type' => 'TEXT', 'nullable' => true],
                    'current_stock' => ['type' => 'INT', 'length' => 11, 'default' => '0'],
                    'minimum_stock' => ['type' => 'INT', 'length' => 11, 'default' => '0'],
                    'maximum_stock' => ['type' => 'INT', 'length' => 11, 'nullable' => true],
                    'unit_cost' => ['type' => 'DECIMAL', 'length' => '10,2', 'nullable' => true],
                    'location' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => true],
                    'supplier_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => true, 'index' => true],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ],
            'stock_movements' => [
                'description' => 'Stock movement history',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'item_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'index' => true],
                    'movement_type' => ['type' => 'ENUM', 'length' => "'in','out','adjustment'", 'nullable' => false],
                    'quantity' => ['type' => 'INT', 'length' => 11, 'nullable' => false],
                    'unit_cost' => ['type' => 'DECIMAL', 'length' => '10,2', 'nullable' => true],
                    'reference' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => true],
                    'notes' => ['type' => 'TEXT', 'nullable' => true],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ],
            'suppliers' => [
                'description' => 'Supplier information',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'contact_person' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => true],
                    'email' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => true],
                    'phone' => ['type' => 'VARCHAR', 'length' => 20, 'nullable' => true],
                    'address' => ['type' => 'TEXT', 'nullable' => true],
                    'is_active' => ['type' => 'TINYINT', 'length' => 1, 'default' => '1'],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ]
        ];
    }

    private function getPricingTiersTables(): array
    {
        return [
            'pricing_plans' => [
                'description' => 'Subscription pricing plans',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'slug' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false, 'index' => true],
                    'description' => ['type' => 'TEXT', 'nullable' => true],
                    'price' => ['type' => 'DECIMAL', 'length' => '10,2', 'nullable' => false],
                    'billing_cycle' => ['type' => 'ENUM', 'length' => "'monthly','yearly','lifetime'", 'nullable' => false],
                    'trial_days' => ['type' => 'INT', 'length' => 11, 'default' => '0'],
                    'is_popular' => ['type' => 'TINYINT', 'length' => 1, 'default' => '0'],
                    'is_active' => ['type' => 'TINYINT', 'length' => 1, 'default' => '1'],
                    'sort_order' => ['type' => 'INT', 'length' => 11, 'default' => '0'],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ],
                'sample_data' => [
                    ['name' => 'Basic', 'slug' => 'basic', 'description' => 'Perfect for individuals', 'price' => '9.99', 'billing_cycle' => 'monthly'],
                    ['name' => 'Pro', 'slug' => 'pro', 'description' => 'Great for small teams', 'price' => '29.99', 'billing_cycle' => 'monthly', 'is_popular' => '1'],
                    ['name' => 'Enterprise', 'slug' => 'enterprise', 'description' => 'For large organizations', 'price' => '99.99', 'billing_cycle' => 'monthly']
                ]
            ],
            'plan_features' => [
                'description' => 'Features included in each plan',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'plan_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'index' => true],
                    'feature_name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'feature_value' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => true],
                    'is_unlimited' => ['type' => 'TINYINT', 'length' => 1, 'default' => '0'],
                    'sort_order' => ['type' => 'INT', 'length' => 11, 'default' => '0'],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ],
            'subscriptions' => [
                'description' => 'User subscriptions',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'user_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'index' => true],
                    'plan_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'index' => true],
                    'status' => ['type' => 'ENUM', 'length' => "'active','cancelled','expired','trial'", 'default' => 'trial'],
                    'trial_ends_at' => ['type' => 'TIMESTAMP', 'nullable' => true],
                    'current_period_start' => ['type' => 'TIMESTAMP', 'nullable' => false],
                    'current_period_end' => ['type' => 'TIMESTAMP', 'nullable' => false],
                    'cancelled_at' => ['type' => 'TIMESTAMP', 'nullable' => true],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ]
        ];
    }

    private function getCMSBasicTables(): array
    {
        return [
            'pages' => [
                'description' => 'CMS pages',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'title' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'slug' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false, 'index' => true],
                    'content' => ['type' => 'LONGTEXT', 'nullable' => false],
                    'meta_title' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => true],
                    'meta_description' => ['type' => 'TEXT', 'nullable' => true],
                    'status' => ['type' => 'ENUM', 'length' => "'draft','published','private'", 'default' => 'draft'],
                    'template' => ['type' => 'VARCHAR', 'length' => 100, 'nullable' => true],
                    'sort_order' => ['type' => 'INT', 'length' => 11, 'default' => '0'],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ],
            'media' => [
                'description' => 'Media files',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'filename' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'original_name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'mime_type' => ['type' => 'VARCHAR', 'length' => 100, 'nullable' => false],
                    'file_size' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false],
                    'file_path' => ['type' => 'VARCHAR', 'length' => 500, 'nullable' => false],
                    'alt_text' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => true],
                    'caption' => ['type' => 'TEXT', 'nullable' => true],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ],
            'menus' => [
                'description' => 'Navigation menus',
                'columns' => [
                    'id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => false, 'auto_increment' => true, 'primary' => true],
                    'parent_id' => ['type' => 'BIGINT', 'length' => 20, 'nullable' => true, 'index' => true],
                    'title' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
                    'url' => ['type' => 'VARCHAR', 'length' => 500, 'nullable' => false],
                    'target' => ['type' => 'ENUM', 'length' => "'_self','_blank'", 'default' => '_self'],
                    'icon' => ['type' => 'VARCHAR', 'length' => 100, 'nullable' => true],
                    'sort_order' => ['type' => 'INT', 'length' => 11, 'default' => '0'],
                    'is_active' => ['type' => 'TINYINT', 'length' => 1, 'default' => '1'],
                    'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP']
                ]
            ]
        ];
    }
}
