<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_content
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Content;

use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the production Article create-mode wiring.
 *
 * @since  __DEPLOY_VERSION__
 */
class ArticleCreateAutosaveWiringTest extends UnitTestCase
{
    /**
     * @testdox  The Article view enables only the optional create provider on the real edit form
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testArticleViewUsesTheCreateProviderAndExistingFormContract(): void
    {
        $view = file_get_contents(
            JPATH_ADMINISTRATOR . '/components/com_content/src/View/Article/HtmlView.php'
        );

        $this->assertStringContainsString('AutosaveCreateProviderInterface', $view);
        $this->assertStringContainsString('AutosaveOperation::InitializeCreate', $view);
        $this->assertStringContainsString('$this->getLayout() !== \'edit\'', $view);
        $this->assertStringContainsString("'com_content.autosave.article'", $view);
        $this->assertStringContainsString("'item-form'", $view);
        $this->assertStringContainsString("['title', 'alias', 'articletext', 'catid']", $view);
    }

    /**
     * @testdox  The browser entrypoint reuses the Article adapter and create binding
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testArticleBrowserWiringUsesOneAdapterAndRuntimeController(): void
    {
        $controller = file_get_contents(
            JPATH_ROOT . '/media_source/com_content/src/article-autosave-controller.es6.js'
        );

        $this->assertStringContainsString('ArticleAutosaveAdapter', $controller);
        $this->assertStringContainsString('ArticleAutosaveCreateBinding', $controller);
        $this->assertStringNotContainsString('CreateAutosaveRuntime', $controller);
        $this->assertStringNotContainsString('ArticleCreateAdapter', $controller);
    }
}
