<?php
/**
 * module.config.php - Batch Config
 *
 * Main Config File for Faucet Batch Module
 *
 * @category Config
 * @package Faucet\Batch
 * @author Verein onePlace
 * @copyright (C) 2021  Verein onePlace <admin@1plc.ch>
 * @license https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0.0
 * @since 1.0.0
 */

namespace OnePlace\Faucet\Batch;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;

return [
    # Livechat Module - Routes
    'router' => [
        'routes' => [
            # Telegram Update WebHook
            'faucet-web-home' => [
                'type'    => Literal::class,
                'options' => [
                    'route' => '/welcome',
                    'defaults' => [
                        'controller' => Controller\BatchController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
        ],
    ],

    # View Settings
    'view_manager' => [
        'template_path_stack' => [
            'batch' => __DIR__ . '/../view',
        ],
    ],

    # Translator
    'translator' => [
        'locale' => 'de_DE',
        'translation_file_patterns' => [
            [
                'type'     => 'gettext',
                'base_dir' => __DIR__ . '/../language',
                'pattern'  => '%s.mo',
            ],
        ],
    ],
];
