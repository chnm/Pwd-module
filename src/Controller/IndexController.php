<?php
namespace Pwd\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    protected $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    public function viewerAction()
    {
        $stmt = $this->conn->prepare('SELECT * FROM pwd_document_instance WHERE id = ?');
        $stmt->execute([$this->params('document-instance-id')]);
        $instance = $stmt->fetch();

        $image = $instance['image_id']
            ? $this->api()->read('items', $instance['image_id'])->getContent()
            : null;
        $pages = $image ? $image->media() : null;
        $document = $instance['document_id']
            ? $this->api()->read('items', $instance['document_id'])->getContent()
            : null;
        $source = $instance['source_id']
            ? $this->api()->read('items', $instance['source_id'])->getContent()
            : null;

        $view = new ViewModel;
        $view->setVariable('instance', $instance);
        $view->setVariable('image', $image);
        $view->setVariable('pages', $pages);
        $view->setVariable('document', $document);
        $view->setVariable('source', $source);
        return $view;
    }
}
