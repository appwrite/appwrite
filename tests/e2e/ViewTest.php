<?php
/**
 * Utopia PHP Framework
 *
 * @package Framework
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/framework
 * @author Eldad Fux <eldad@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Tests\E2E;

use PHPUnit\Framework\TestCase;

class ViewTest extends TestCase
{
    /**
     * @var View
     */
    protected $view = null;

    public function setUp()
    {
        $this->view = new View(__DIR__ . '/mocks/View/template.phtml');
    }

    public function tearDown()
    {
        $this->view = null;
    }

    public function testSetParam()
    {
        $value = $this->view->setParam('key', 'value');

        // Assertions
        $this->assertInstanceOf('Utopia\View', $value);
    }

    public function testGetParam()
    {
        $this->view->setParam('key', 'value');

        // Assertions
        $this->assertEquals('value', $this->view->getParam('key', 'default'));
        $this->assertEquals('default', $this->view->getParam('fake', 'default'));
    }

    public function testSetPath()
    {
        $value = $this->view->setPath('mocks/View/fake.phtml');

        // Assertions
        $this->assertInstanceOf('Utopia\View', $value);
    }

    public function testSetRendered()
    {
        $this->view->setRendered();

        // Assertions
        $this->assertEquals(true, $this->view->isRendered());
    }

    public function testIsRendered()
    {
        // Assertions
        $this->view->setRendered(false);
        $this->assertEquals(false, $this->view->isRendered());

        // Assertions
        $this->view->setRendered(true);
        $this->assertEquals(true, $this->view->isRendered());
    }

    public function testRender()
    {
        // Assertions
        $this->assertEquals('<div>Test template mock</div>', $this->view->render());

        $this->view->setRendered();
        $this->assertEquals('', $this->view->render());

        try {
            $this->view->setRendered(false);
            $this->view->setPath('just-a-broken-string.phtml');
            $this->view->render();
        }
        catch(\Exception $e) {
            return;
        }

        $this->fail('An expected exception has not been raised.');
    }

    public function testEscape()
    {
        // Assertions
        $this->assertEquals('&amp;&quot;', $this->view->print('&"', View::FILTER_ESCAPE));
    }

    public function testNl2p()
    {
        // Assertions
        $this->assertEquals('<p>line1</p><p>line2</p>', $this->view->print("line1\n\nline2", View::FILTER_NL2P));
    }
}