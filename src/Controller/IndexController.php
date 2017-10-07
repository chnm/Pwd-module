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
        $stmt = $this->conn->prepare('SELECT * FROM pwd_document_image WHERE id = ?');
        $stmt->execute([$this->params('document-image-id')]);
        $documentImage = $stmt->fetch();

        $image = $this->api()->read('items', $documentImage['image_id'])->getContent();
        $document = $this->api()->read('items', $documentImage['document_id'])->getContent();
        $source = $this->api()->read('items', $documentImage['source_id'])->getContent();

        $view = new ViewModel;
        $view->setVariable('documentImage', $documentImage);
        $view->setVariable('image', $image);
        $view->setVariable('document', $document);
        $view->setVariable('source', $source);
        return $view;
    }
}
