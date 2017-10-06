<?php
namespace Pwd\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    public function viewerAction()
    {
        $response = $this->api()->read('items', $this->params('image-id'));
        $image = $response->getContent();
        if ('pwd:Image' !== $image->resourceClass()->term()) {
            throw new \Exception('Item must be a pwd:Image to view.');
        }

        $view = new ViewModel;
        $item = $response->getContent();
        $view->setVariable('image', $image);
        return $view;
    }
}
