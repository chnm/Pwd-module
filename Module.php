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

        $documentImages = [];
        $tables = ['pwd_document_collection', 'pwd_document_microfilm', 'pwd_document_publication'];
        foreach ($tables as $table) {
            $sql = "SELECT * FROM $table WHERE document_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$item->id()]);
            $documentImages[$table] = $stmt->fetchAll();
        }
        return $documentImages;
    }

    protected function getImageDocuments($item)
    {
        $conn = $this->getServiceLocator()->get('Omeka\Connection');

        $imageDocuments = [];
        $tables = ['pwd_document_collection', 'pwd_document_microfilm', 'pwd_document_publication'];
        foreach ($tables as $table) {
            $sql = "SELECT * FROM $table WHERE image_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$item->id()]);
            $imageDocuments[$table] = $stmt->fetchAll();
        }
        return $imageDocuments;
    }
}
