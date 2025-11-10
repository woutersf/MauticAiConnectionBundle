<?php

namespace MauticPlugin\MauticAIconnectionBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;

class AiConnectionIntegration extends AbstractIntegration
{
    public function getName(): string
    {
        return 'AiConnection';
    }

    public function getDisplayName(): string
    {
        return 'AI Connection Settings';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    public function getRequiredKeyFields(): array
    {
        return [
            'litellm_endpoint' => 'LiteLLM Endpoint',
            'litellm_secret_key' => 'LiteLLM Secret Key',
        ];
    }

    /**
     * Get the path to the integration icon
     */
    public function getIcon(): string
    {
        return 'plugins/MauticAIconnectionBundle/Assets/img/mauticai.png';
    }
}
