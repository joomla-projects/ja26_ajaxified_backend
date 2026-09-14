<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Content
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Content\Fixtures;

use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Component\Content\Administrator\Model\ArticlesModel;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
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

    public function prepareFields(array $filters, bool $explicit = false, ?User $user = null): PreparedFieldsFilter
    {
        return $this->service->prepare('com_content.article', $filters, $user ?? new User(), $explicit);
    }

    public function syncFieldsForm(Form $form): void
    {
        $method   = new \ReflectionMethod(ArticlesModel::class, 'getPreparedFieldsFilter');
        $prepared = $method->invoke($this);

        if (!$form->getField('customfield_7', 'filter')) {
            $this->service->augmentForm($form, $prepared);
        }

        $this->service->bindForm($form, $prepared);
    }
}
