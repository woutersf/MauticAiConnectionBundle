<?php

declare(strict_types=1);

return [
    'name'        => 'Mautic AI Connection',
    'description' => 'Core AI connection plugin for Mautic AI features - manages LiteLLM integration',
    'version'     => '1.0.0',
    'author'      => 'Frederik Wouters',
    'icon'        => 'plugins/MauticAIconnectionBundle/Assets/img/mauticai.png',

    'routes' => [],

    'services' => [
        'integrations' => [
            'mautic.integration.aiconnection' => [
                'class' => \MauticPlugin\MauticAIconnectionBundle\Integration\AiConnectionIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'session',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                ],
            ],
        ],
        'other' => [
            'mautic.ai_connection.service.litellm' => [
                'class' => \MauticPlugin\MauticAIconnectionBundle\Service\LiteLLMService::class,
                'arguments' => [
                    'mautic.http.client',
                    'mautic.helper.core_parameters',
                    'monolog.logger.mautic',
                    'mautic.helper.integration',
                ],
                'public' => true,
            ],
        ],
    ],

    'parameters' => [],
];
