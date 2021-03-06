<?php
namespace AtAdmin\Controller;

use SlmLocale\Locale\Detector;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use AtDataGrid\DataGrid\Manager;
use Zend\Mvc\View\Http\InjectTemplateListener;
use Nette\Diagnostics\Debugger;
use AtDataGrid\DataGrid\Filter\Sql\Like;
use Zend\Json\Json;
use Doctrine\ORM\EntityManager;
use AtAdmin\Entity\ColumnState;
use Zend\View\Model\JsonModel;
use AtDataGrid\DataGrid\Column\Column;
use Zend\Http\PhpEnvironment\Request;
use Gedmo\Sluggable\Util\Urlizer;
use Gedmo\Translatable\TranslatableListener;
use Doctrine\Common\Annotations\AnnotationReader;
use Gedmo\Mapping\Annotation\Slug;

abstract class AbstractDataGridController extends AbstractActionController
{

    /**
     *
     * @return array \Zend\View\Model\ViewModel
     */
    public function indexAction()
    {
        return new ViewModel();
    }

    /**
     *
     * @return void
     */
    public function listAction()
    {
        if ($this->params()->fromPost('dataGridColumnState')) {
            return $this->saveColumnsStateAction();
        }
        // Save back url to redirect after actions
        $this->backTo()->setBackUrl();
        
        // Configure grid
        $gridManager = $this->getGridManager();
        $grid = $gridManager->getGrid();
        
        $grid->getColumns();
        
        $recursiveApplyFilters = function ($typeFilter, $columnParent = null) use($grid, &$recursiveApplyFilters)
        {
            foreach ($typeFilter as $column => $filter) {
                if (empty($filter))
                    continue;
                
                if (is_array($filter)) {
                    $recursiveApplyFilters($filter, $grid->getColumn($column));
                    continue;
                }
                
                $filter = 'AtDataGrid\DataGrid\Filter\Sql\\' . $filter;
                if ($columnParent !== null) {
                    $columnParent->getColumn($column)
                        ->clearFilters()
                        ->addFilter(new $filter());
                } else {
                    $grid->getColumn($column)
                        ->clearFilters()
                        ->addFilter(new $filter());
                }
            }
        };
        
        $em = $grid->getDataSource()->getEm();
        
        $em instanceof EntityManager;
        $repo = $em->getRepository('AtAdmin\Entity\ColumnState');
        
        $grid->setOrder($this->params()
            ->fromQuery('order', Json::encode(array(
            $grid->getIdentifierColumnName()
        )) . '~desc'));
        $grid->setCurrentPage($this->params()
            ->fromQuery('page'));
        $grid->setItemsPerPage($this->params()
            ->fromQuery('show_items'));
        
        if (($cmd = $this->params()->fromPost('cmd', null)) === null) {
            $requestParams = $this->getRequest()->getQuery();
            
            $typeFilter = $this->params()->fromQuery('typeFilter', array());
            $recursiveApplyFilters($typeFilter);
            
            $filtersForm = $grid->getFiltersForm();
            foreach ($filtersForm->getElements() as $e) {
                $inputFilter = $filtersForm->getInputFilter()->get($e->getName());
                $inputFilter->setAllowEmpty(true);
            }
            
            $filtersForm->setData($requestParams);
            
            if ($filtersForm->isValid()) {
                $grid->applyFilters($filtersForm->getData());
            } else {
                // no ha pasado la validacion
            }
            
            $viewModel = new ViewModel(array(
                'gridManager' => $gridManager
            ));
            
            $this->getEvent()->setResult($viewModel);
            $injectTemplateListener = new InjectTemplateListener();
            $injectTemplateListener->injectTemplate($this->getEvent());
            $model = $this->getEvent()->getResult();
            $originalTemplate = $model->getTemplate();
            $originalTemplateBase = dirname($originalTemplate);
            
            $gridManager->setOriginalTemplateBase($originalTemplateBase);
            
            $viewResolver = $this->getServiceLocator()->get('ViewResolver');
            
            // miramos si existe el original
            if (false === $viewResolver->resolve($originalTemplateBase . '/grid'))
                $viewModel->setTemplate('at-datagrid/grid');
            
            return $viewModel;
        } else {
            return $this->forward()->dispatch($this->params('controller'), array(
                'action' => $cmd
            ));
        }
    }
    
    // CRUD
    
    /**
     *
     * @throws \Exception
     */
    public function createAction()
    {
        $gridManager = $this->getGridManager();
        $grid = $gridManager->getGrid();
        
        if (! $gridManager->isAllowCreate()) {
            throw new \Exception('You are not allowed to do this.');
        }
        
        $requestParams = $this->getRequest()->getPost();
        
        $form = $gridManager->getForm();
        
        $entityClassName = $grid->getDataSource()->getEntity();
        $entity = new $entityClassName();

        try {
            $detector = $this->getServiceLocator()->get('SlmLocale\Locale\Detector');
        } catch (\Exception $e) {
            $detector = false;
        }

        if ($grid->getDataSource()->isTranslationTable() && $detector && count($detector->getSupported()) > 1) {
            $entity->setLocale($this->params()
                ->fromQuery('locale', \Locale::getDefault()));
            
            // hacemos esto por si no esta definida la columna locale dentro de la entidad y es una referencia inexistente utilizada internamente
            $form->get('locale')->setValue($entity->getLocale());
        } else {
            if($form->has('locale')) {
                $form->getInputFilter()->remove('locale');
                $form->remove('locale');
            }
        }
        
        $form->bind($entity);
        
        // foreach ($requestParams as $k => $param) {
        // if (empty($requestParams[$k]) && (@$requestParams[$k] !== '0')) {
        // $requestParams[$k] = null;
        // }
        // }
        
        $reader = new AnnotationReader();
        foreach ($requestParams as $k => $param) {
        
            try {
                $refPClass = new \ReflectionProperty($grid->getDataSource()->getEntity(), $k);
            }  catch (\Exception $e) {
                continue;
            }
        
            $propertyAnnotations = $reader->getPropertyAnnotations($refPClass);
            foreach($propertyAnnotations as $kk => $class) {
                if($class instanceof Slug) {
                    if (empty($requestParams[$k])) {
                        $requestParams[$k] = null;
                    }
                }
            }
        }
        
        $form->setData($requestParams);

        if ($this->getRequest()->isPost()) {
            if ($form->isValid()) {
                $formData = $this->preSave($form);
                $itemId = $grid->save($this->getEventManager(), $formData);
                $this->postSave($grid, $itemId);
                
                $this->backTo()->goBack('Item creado');
            }

        }
        
        $viewModel = new ViewModel(array(
            'gridManager' => $gridManager
        ));
        
        $this->getEvent()->setResult($viewModel);
        $injectTemplateListener = new InjectTemplateListener();
        $injectTemplateListener->injectTemplate($this->getEvent());
        $model = $this->getEvent()->getResult();
        $originalTemplate = $model->getTemplate();
        $originalTemplateBase = dirname($originalTemplate);

        $viewResolver = $this->getServiceLocator()->get('ViewResolver');
        
        // miramos si existe el original
        if (false === $viewResolver->resolve($originalTemplate))
            $viewModel->setTemplate('at-datagrid/create');
        
        $originalFormTemplate = dirname($originalTemplate) . '/form';
        $viewModel->setVariable('formTemplate', $originalFormTemplate);
        // miramos si existe el original
        if (false === $viewResolver->resolve(dirname($originalTemplate) . '/form'))
            $viewModel->setVariable('formTemplate', 'at-datagrid/form');
        
        return $viewModel;
    }

    /**
     *
     * @return \Zend\View\Model\ViewModel
     * @throws \Exception
     */
    public function editAction()
    {
        $gridManager = $this->getGridManager();
        $grid = $gridManager->getGrid();
        
        if (! $gridManager->isAllowEdit()) {
            throw new \Exception('No se le permite hacer esto.');
        }
        
        $itemId = $this->params('id');
        if (! $itemId) {
            throw new \Exception('No se encontró registro.');
        }
        
        $requestParams = $this->getRequest()->getPost();
        
        $form = $gridManager->getForm();
        
        $item = $grid->getRow($itemId);

        $defaultLang = str_replace('_', '-', \Locale::getDefault());
        
        try {
            $detector = $this->getServiceLocator()->get('SlmLocale\Locale\Detector');
            $configSlm = $this->getServiceLocator()->get('config');
            $configSlm = $configSlm['slm_locale'];
            /**
             * @var $detector Detector
             */

            if(isset($configSlm['aliases'][$detector->getDefault()])) {
                $defaultLang = $configSlm['aliases'][$detector->getDefault()];
            } else {
                $defaultLang = $detector->getDefault();
            }
        } catch (\Exception $e) {
            $detector = false;
        }

        $events = $grid->getDataSource()
            ->getEm()
            ->getEventManager()
            ->getListeners();
        foreach ($events as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof TranslatableListener) {
                    $listener->setTranslatableLocale($defaultLang);
                    $listener->setPersistDefaultLocaleTranslation(true);
                }
            }
        }

        if (method_exists($item, 'setLocale') && $detector && count($detector->getSupported()) > 1) {
            $item->setLocale($this->params()
                ->fromQuery('locale', \Locale::getDefault()));
            $grid->getDataSource()
                ->getEm()
                ->refresh($item);
            $form->bind($item);
            $form->get('locale')->setValue($item->getLocale());
        } else {
            $em = $grid->getDataSource()->getEm();
            /**
             * @var EntityManager $em
             */
            foreach ($events as $event => $listeners) {
                foreach ($listeners as $listener) {
                    if ($listener instanceof TranslatableListener) {
                        $em->getEventManager()->removeEventListener($event, $listener);
                    }
                }
            }

            if($form->has('locale')) {
                $form->getInputFilter()->remove('locale');
                $form->remove('locale');
            }
        }
        
        $form->bind($item);
        
        // foreach ($requestParams as $k => $param) {
        // if (empty($requestParams[$k]) && (@$requestParams[$k] !== '0')) {
        // $requestParams[$k] = null;
        // }
        // }
        $reader = new AnnotationReader();
        foreach ($requestParams as $k => $param) {
            
            try {
                $refPClass = new \ReflectionProperty($grid->getDataSource()->getEntity(), $k);
            }  catch (\Exception $e) {
            	continue;
            }
            
            $propertyAnnotations = $reader->getPropertyAnnotations($refPClass);
            foreach($propertyAnnotations as $kk => $class) {
            	if($class instanceof Slug) {
            	    if (empty($requestParams[$k])) {
            	       $requestParams[$k] = null;
            	    }
            	}
            }
        }
        
        $form->setData($requestParams);
        
        if ($this->getRequest()->isPost() && $form->isValid()) {
            $data = $this->preSave($form);
            
            $grid->save($this->getEventManager(), $data, $itemId);
            $this->postSave($grid, $itemId);
            
            return $this->backTo()->goBack('Record updated.');
        }
        
        if (! $grid->getCaption()) {
            $title = $item;
            if ($grid->getTitleColumnName()->getParent()) {
                $parent = $grid->getTitleColumnName()->getParent();
                $title = $title->{"get{$parent->getName()}"}()->{"get{$grid->getTitleColumnName()->getName()}"}();
            } else {
                $title = $title->{"get{$grid->getTitleColumnName()->getName()}"}();
            }
            
            $grid->setCaption((string) $title);
        }
        
        $routeMatch = $this->getEvent()->getRouteMatch();
        $router = $this->getEvent()->getRouter();
        $routeMatchName = $routeMatch->getMatchedRouteName();
        
        $navigation = $this->getServiceLocator()
            ->get('viewrenderer')
            ->getEngine()
            ->plugin('navigation', array(
            'navigation'
        ));
        
        $container = $navigation->setContainer('admin_navigation')->getContainer();
        $container instanceof \Zend\Navigation\Navigation;
        
        $container = $container->findOneBy('route', $routeMatchName);
        
        if ($container) {
            $container = $container->findOneBy('params', array(
                'action' => 'list'
            ));
        }
        
        if ($container) {
            $container instanceof \Zend\Navigation\Page\Mvc;
            $pages = new \Zend\Navigation\Page\Mvc(array(
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
        
        // $currentPanel = $this->getRequest()->getParam('panel');
        // $this->view->panel = $currentPanel;
        $this->getPluginManager()->get('backTo');
        $varsModel = array(
            'gridManager' => $gridManager,
            'item' => $item,
            'backUrl' => $this->backTo()->getBackUrl(false)
        );
        $viewModel = new ViewModel($varsModel);
        
        $this->getEvent()->setResult($viewModel);
        $injectTemplateListener = new InjectTemplateListener();
        $injectTemplateListener->injectTemplate($this->getEvent());
        $model = $this->getEvent()->getResult();
        $originalTemplate = $model->getTemplate();
        $originalTemplateBase = dirname($originalTemplate);
        
        $viewResolver = $this->getServiceLocator()->get('ViewResolver');
        
        // miramos si existe el original
        if (false === $viewResolver->resolve($originalTemplate))
            $viewModel->setTemplate('at-datagrid/edit');
            
            // sumary panels
        $viewPanelSumary = new ViewModel($varsModel);
        $viewPanelSumary->setTemplate($originalTemplateBase . '/panels/summary');
        if (false === $viewResolver->resolve($viewPanelSumary->getTemplate()))
            $viewPanelSumary->setTemplate('at-datagrid/panels/summary');
            
            // formulario
        $viewForm = new ViewModel($varsModel);
        $viewForm->setTemplate($originalTemplateBase . '/form');
        if (false === $viewResolver->resolve($viewForm->getTemplate()))
            $viewForm->setTemplate('at-datagrid/form');
        
        $viewModel->addChild($viewPanelSumary, 'viewPanelsSumary')->addChild($viewForm, 'viewForm');
        
        return $viewModel;
    }

    /**
     *
     * @throws \Exception
     */
    public function deleteAction()
    {
        $gridManager = $this->getGridManager();
        $grid = $gridManager->getGrid();
        
        if (! $gridManager->isAllowDelete()) {
            throw new \Exception('You are not allowed to do this.');
        }
        
        $itemId = $this->params()->fromPost('items', $this->params('id'));
        if (! $itemId) {
            throw new \Exception('No record found.');
        }
        
        foreach ((array) $itemId as $id) {
            $grid->delete($id);
        }
        
        return $this->backTo()->goBack('Record deleted.');
    }

    /**
     * Hook before save row
     *
     * @todo : Use event here. See ZfcBase EventAwareForm
     *      
     * @param
     *            $form
     * @return mixed
     */
    public function preSave($form)
    {
        $data = $form->getData();
        $params = compact('data');
        $this->getEventManager()->trigger(__FUNCTION__, $this, $params);
        return $data;
    }

    /**
     * Hook after save row
     *
     * @todo Use event here
     *      
     * @param
     *            $grid
     * @param
     *            $primary
     */
    public function postSave($grid, $primary)
    {
        $params = compact('grid', 'primary');
        $this->getEventManager()->trigger(__FUNCTION__, $this, $params);
        return;
    }

    public function saveColumnsStateAction()
    {
        $gridManager = $this->getGridManager();
        $em = $gridManager->getGrid()
            ->getDataSource()
            ->getEm();
        
        $em instanceof EntityManager;
        $repo = $em->getRepository('AtAdmin\Entity\ColumnState');
        
        $columns = $this->params()->fromPost('columns');
        
        foreach ((array) $columns as $column) {
            $entity = $repo->findOneBy(array(
                'column' => $column['column'],
                'user' => $this->zfcUserAuthentication()
                    ->getIdentity()
            ));
            if (! $entity)
                $entity = new ColumnState();
            
            $entity->setPosition($column['position']);
            $entity->setStatus((bool) $column['state']);
            $entity->setUser($this->zfcUserAuthentication()
                ->getIdentity());
            $entity->setColumn($column['column']);
            
            $em->persist($entity);
            $em->flush($entity);
        }
        
        $jsonmodel = new JsonModel(array(
            'result' => true
        ));
        $jsonmodel->setTerminal(true);
        return $jsonmodel;
    }

    public function exportAction()
    {
        $type = strtoupper($this->params()->fromQuery('type'));
        
        switch ($type) {
            case 'PDF':
                return $this->pdfExport();
                break;
        }
    }

    protected function pdfExport()
    {
        set_time_limit(0);
        ini_set('memory_limit', '2000M');
        
        $viewModel = $this->listAction();
        $gridManager = $viewModel->getVariable('gridManager');
        $gridManager instanceof Manager;
        $gridManager->getGrid()->setItemsPerPage(- 1);
        
        if ($this->params()->fromQuery('exec')) {
            $request = $this->getRequest();
            $request instanceof Request;
            $url = $this->url()->fromRoute(null, array(), array(
                'force_canonical' => true,
                'query' => (array(
                    'exec' => null
                ) + $request->getQuery()
                    ->toArray())
            ), true);
            $name = Urlizer::transliterate($viewModel->getVariable('gridManager')
                ->getGrid()
                ->getCaption());
            ob_start();
            passthru('wkhtmltopdf '.$url.' -', $result);
            header('Content-type: application/pdf');
            header('Content-Disposition: attachment; filename="'.$name.'.pdf"');
            echo ob_get_clean();
            exit;
        }
        $viewModel->setTemplate('at-datagrid/export/pdf');
        
        return $viewModel;
    }

    /**
     * @return Manager
     */
    abstract public function getGridManager();
}
