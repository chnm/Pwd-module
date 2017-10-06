<?php
namespace Pwd;

use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return [
            'view_manager' => [
                'template_path_stack' => [
                    OMEKA_PATH . '/modules/Pwd/view',
                ],
            ],
            'controllers' => [
                'invokables' => [
                    'Pwd\Controller\Index' => 'Pwd\Controller\IndexController',
                ],
            ],
            'router' => [
                'routes' => [
                    'admin' => [
                        'child_routes' => [
                            'pwd' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/pwd',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Pwd\Controller',
                                        'controller' => 'Index',
                                        'action' => 'index',
                                    ],
                                ],
                                'may_terminate' => true,
                                'child_routes' => [
                                    'viewer' => [
                                        'type' => 'Segment',
                                        'options' => [
                                            'route' => '/viewer/:image-id[/:document-image-id]',
                                            'constraints' => [
                                                'image-id' => '\d+',
                                                'document-image-id' => '\d+',
                                            ],
                                            'defaults' => [
                                                'action' => 'viewer',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.section_nav',
            function (Event $event) {
                $view = $event->getTarget();
                $item = $view->item;
                if ($this->isClass('pwd:Document', $item)) {
                    $sectionNav = $event->getParam('section_nav');
                    $sectionNav['pwd-document-images'] = 'Images';
                    $event->setParam('section_nav', $sectionNav);
                }
                if ($this->isClass('pwd:Image', $item)) {
                    $sectionNav = $event->getParam('section_nav');
                    $sectionNav['pwd-image-documents'] = 'Documents';
                    $event->setParam('section_nav', $sectionNav);
                }
            }
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.after',
            function (Event $event) {
                $view = $event->getTarget();
                $item = $view->item;
                if ($this->isClass('pwd:Document', $item)) {
                    echo $view->partial('pwd/document-images', [
                        'documentImages' => $this->getDocumentImages($item),
                    ]);
                }
                if ($this->isClass('pwd:Image', $item)) {
                    echo $view->partial('pwd/image-documents', [
                        'imageDocuments' => $this->getImageDocuments($item),
                    ]);
                }
            }
        );
    }

    protected function isClass($className, $item)
    {
        $class = $item->resourceClass();
        if (!$class) {
            return false;
        }
        if ($className !== $class->term()) {
            return false;
        }
        return true;
    }

    protected function getDocumentImages($item)
    {
        $conn = $this->getServiceLocator()->get('Omeka\Connection');
        $stmt = $conn->prepare('SELECT * FROM pwd_document_image WHERE document_id = ?');
        $stmt->execute([$item->id()]);
        return $stmt->fetchAll();
    }

    protected function getImageDocuments($item)
    {
        $conn = $this->getServiceLocator()->get('Omeka\Connection');
        $stmt = $conn->prepare('SELECT * FROM pwd_document_image WHERE image_id = ?');
        $stmt->execute([$item->id()]);
        return $stmt->fetchAll();
    }
}
