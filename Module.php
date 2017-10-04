<?php
namespace Pwd;

use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return [];
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.section_nav',
            function (Event $event) {
                $item = $event->getTarget()->item;
                if (!$item) {
                    return;
                }
                $sectionNav = $event->getParam('section_nav');
                $sectionNav['pwd-item-linked'] = 'Linked resources (PWD)';
                $event->setParam('section_nav', $sectionNav);
            }
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.after',
            function (Event $event) {
                echo '<div id="pwd-item-linked" class="section"><p>Linked resources (PWD)</p></div>';
            }
        );
    }
}
