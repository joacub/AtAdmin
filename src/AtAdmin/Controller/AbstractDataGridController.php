<?php

namespace AtAdmin\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use AtDataGrid\DataGrid\Manager;
use Zend\Mvc\View\Http\InjectTemplateListener;

abstract class AbstractDataGridController extends AbstractActionController
{
   /**
     * @return array|\Zend\View\Model\ViewModel
     */
    public function indexAction()
    {
        return new ViewModel();
    }

    /**
     * @return void
     */
    public function listAction()
    {
        // Save back url to redirect after actions
        $this->backTo()->setBackUrl();

        // Configure grid
        $gridManager = $this->getGridManager();
        $grid = $gridManager->getGrid();

        $grid->setOrder($this->params()->fromQuery('order', $grid->getIdentifierColumnName().'~desc'));
        $grid->setCurrentPage($this->params()->fromQuery('page'));
        $grid->setItemsPerPage($this->params()->fromQuery('show_items'));

        if (($cmd = $this->params()->fromPost('cmd', null)) === null) {
            $requestParams = $this->getRequest()->getQuery();

            $filtersForm = $grid->getFiltersForm();
            foreach($filtersForm->getElements() as $e) {
            	$inputFilter = $filtersForm->getInputFilter()->get($e->getName());
            	$inputFilter->setAllowEmpty(true);
            }
            
            $filtersForm->setData($requestParams);

            if ($filtersForm->isValid()) {
                $grid->applyFilters($filtersForm->getData());
            } else {
            	//no ha pasado la validacion
            }
            
            $viewModel = new ViewModel(array('gridManager' => $gridManager));
	        
	        $this->getEvent()->setResult($viewModel);
	        $injectTemplateListener  = new InjectTemplateListener();
	        $injectTemplateListener->injectTemplate($this->getEvent());
	        $model = $this->getEvent()->getResult();
	        $originalTemplate = $model->getTemplate();
	        $originalTemplateBase = dirname($originalTemplate);
	        
	        $viewResolver = $this->getServiceLocator()->get('ViewResolver');
	        
	        //miramos si existe el original
	        if(false === $viewResolver->resolve($originalTemplate))
	        	$viewModel->setTemplate('at-datagrid/grid');

            return $viewModel;
        } else {
            return $this->forward()->dispatch($this->params('controller'), array('action' => $cmd));
        }
    }

    // CRUD

    /**
     * @throws \Exception
     */
    public function createAction()
    {
        $gridManager = $this->getGridManager();
        $grid = $gridManager->getGrid();

        if (!$gridManager->isAllowCreate()) {
            throw new \Exception('You are not allowed to do this.');
        }

        $requestParams = $this->getRequest()->getPost();

        $form = $gridManager->getForm();
        
        $entityClassName = $grid->getDataSource()->getEntity();
        $entity = new $entityClassName();
        
        if($grid->getDataSource()->isTranslationTable()) {
        	$entity->setLocale($this->params()->fromQuery('locale', \Locale::getDefault()));
        
        	//hacemos esto por si no esta definida la columna locale dentro de la entidad y es una referencia inexistente utilizada internamente
        	$form->get('locale')->setValue($entity->getLocale());
        }
        
        $form->bind($entity);
        $form->setData($requestParams);

        if ($this->getRequest()->isPost()) {
	        if ($form->isValid()) {
	            $formData = $this->preSave($form);
	            $itemId = $grid->save($formData);
	            $this->postSave($grid, $itemId);
	
	            $this->backTo()->goBack('Item creado');
	        }
        }

        $viewModel = new ViewModel(array('gridManager' => $gridManager));
        
        $this->getEvent()->setResult($viewModel);
        $injectTemplateListener  = new InjectTemplateListener();
        $injectTemplateListener->injectTemplate($this->getEvent());
        $model = $this->getEvent()->getResult();
        $originalTemplate = $model->getTemplate();
        $originalTemplateBase = dirname($originalTemplate);
        
        $viewResolver = $this->getServiceLocator()->get('ViewResolver');
        
        //miramos si existe el original
        if(false === $viewResolver->resolve($originalTemplate))
        	$viewModel->setTemplate('at-datagrid/create');

        return $viewModel;
    }

    /**
     * @return \Zend\View\Model\ViewModel
     * @throws \Exception
     */
    public function editAction()
    {
        $gridManager = $this->getGridManager();
        $grid = $gridManager->getGrid();

        if (!$gridManager->isAllowEdit()) {
            throw new \Exception('No se le permite hacer esto.');
        }

        $itemId = $this->params('id');
        if (!$itemId) {
            throw new \Exception('No se encontró registro.');
        }

        $requestParams = $this->getRequest()->getPost();

        $form = $gridManager->getForm();
        
        $item = $grid->getRow($itemId);
        
        if(method_exists($item, 'setLocale')) {
        	$item->setLocale($this->params()->fromQuery('locale', \Locale::getDefault()));
        	$grid->getDataSource()->getEm()->refresh($item);
        	$form->bind($item);
        	$form->get('locale')->setValue($item->getLocale());
        
        }
        
        $form->bind($item);
        
        $form->setData($requestParams);
        
        if ($this->getRequest()->isPost() && $form->isValid()) {
            $data = $this->preSave($form);
            $grid->save($data, $itemId);
            $this->postSave($grid, $itemId);

            return $this->backTo()->goBack('Record updated.');
        }

        
        if(!$grid->getCaption()) {
        	$partsNamedCollumn = explode('__', $grid->getTitleColumnName());
        	$partsParsed = array();
        	$title = $item;
        	foreach($partsNamedCollumn as $partCollumn) {
        		$titleColumn = ucfirst($partCollumn);
        		$title = $title->{"get{$titleColumn}"}();
        	}
        		
        	$titleColumn = ucfirst($grid->getTitleColumnName());
        	$grid->setCaption((string) $title);
        }
        
        
        $serviceLocator = $this->getServiceLocator()->get('Application');
        $routeMatch  = $serviceLocator->getMvcEvent()->getRouteMatch();
        $router      = $serviceLocator->getMvcEvent()->getRouter();
        $routeMatchName = $routeMatch->getMatchedRouteName();
        
        $navigation = $this->getEvent()->getApplication()->getServiceManager()->get('viewrenderer')->getEngine()->plugin('navigation', array('navigation'));
        
        $container = $navigation->setContainer('admin_navigation')->getContainer();
        $container instanceof \Zend\Navigation\Navigation;
        
        $container = $container->findOneBy('route',
        		$routeMatchName);
        
        if($container) {
        	$container = $container->findOneBy('params', array('action' => 'list'));
        }
        
        if($container) {
        	$container instanceof \Zend\Navigation\Page\Mvc;
        	$pages = new \Zend\Navigation\Page\Mvc(
        			array(
        				'label' => $grid->getCaption(),
        				'route' => $routeMatchName,
        				'params' => array(
        					'action' => 'edit',
        					'id' => $this->params('id')
        				),
        				'visible' => false
        			));
        
        
        	$pages->setRouteMatch($routeMatch);
        	$pages->setDefaultRouter($router);
        
        	$container->addPage($pages);
        }

        //$currentPanel = $this->getRequest()->getParam('panel');
        //$this->view->panel = $currentPanel;

        $varsModel = array(
            'gridManager' => $gridManager,
            'item'        => $item,
            'backUrl'     => $this->backTo()->getBackUrl(false)
        );
        $viewModel = new ViewModel($varsModel);
        
        $this->getEvent()->setResult($viewModel);
        $injectTemplateListener  = new InjectTemplateListener();
        $injectTemplateListener->injectTemplate($this->getEvent());
        $model = $this->getEvent()->getResult();
        $originalTemplate = $model->getTemplate();
        $originalTemplateBase = dirname($originalTemplate);
        
        $viewResolver = $this->getServiceLocator()->get('ViewResolver');
        
        //miramos si existe el original
        if(false === $viewResolver->resolve($originalTemplate))
        	$viewModel->setTemplate('at-datagrid/edit');
        
        //sumary panels
        $viewPanelSumary = new ViewModel($varsModel);
        $viewPanelSumary->setTemplate($originalTemplateBase . '/panels/summary');
        if(false === $viewResolver->resolve($viewPanelSumary->getTemplate()))
        	$viewPanelSumary->setTemplate('at-datagrid/panels/summary');
        
        //formulario
        $viewForm = new ViewModel($varsModel);
        $viewForm->setTemplate($originalTemplateBase . '/form');
        if(false === $viewResolver->resolve($viewForm->getTemplate()))
        	$viewForm->setTemplate('at-datagrid/form');
        
        $viewModel->addChild($viewPanelSumary, 'viewPanelsSumary')
        ->addChild($viewForm, 'viewForm');

        return $viewModel;
    }

    /**
     * @throws \Exception
     */
    public function deleteAction()
    {
        $gridManager = $this->getGridManager();
        $grid = $gridManager->getGrid();

        if (!$gridManager->isAllowDelete()) {
            throw new \Exception('You are not allowed to do this.');
        }

        $itemId = $this->params()->fromPost('items', $this->params('id'));
        if (!$itemId) {
            throw new \Exception('No record found.');
        }

        foreach((array) $itemId as $id) {
        	$grid->delete($id);
        }
        
        return $this->backTo()->goBack('Record deleted.');
    }

    /**
     * Hook before save row
     * @todo: Use event here. See ZfcBase EventAwareForm
     *
     * @param $form
     * @return mixed
     */
    public function preSave($form)
    {
        $data = $form->getData();
        return $data;
    }

    /**
     * Hook after save row
     * @todo Use event here
     *
     * @param $grid
     * @param $primary
     */
    public function postSave($grid, $primary)
    {
        return;
    }

    /**
     * @return Manager
     */
    abstract public function getGridManager();
}