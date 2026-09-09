<?php

namespace Joomla\Tests\Unit\Administrator\Components\Content\Fixtures;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Content\Administrator\Model\ArticlesModel;
use Joomla\Component\Fields\Administrator\Service\FieldsFilterService;

class TestArticlesModel extends ArticlesModel
{
    protected $option = 'com_content';

    public function __construct(
        private readonly FieldsFilterService $service,
        array $config,
        MVCFactoryInterface $factory,
    ) {
        parent::__construct($config, $factory);
    }

    protected function getFieldsFilterService(): FieldsFilterService
    {
        return $this->service;
    }

    public function fieldsFingerprint(): string
    {
        return parent::getStoreId('fields-test');
    }

    public function contextName(): string
    {
        return $this->context;
    }
}
