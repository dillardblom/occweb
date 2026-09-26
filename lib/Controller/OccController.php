<?php

namespace OCA\OCCWeb\Controller;

use Exception;
use OC;
use OC\Console\Application;
use OC\MemoryInfo;
use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Controller;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

class OccController extends Controller
{
  private $logger;
  private $userId;

  private $application;
  private $symphonyApplication;
  private $output;

  public function __construct($AppName, IRequest $request, $userId)
  {
    parent::__construct($AppName, $request);
    $this->logger = OC::$server->get(LoggerInterface::class);
    $this->userId = $userId;

    $this->application = new Application(
      OC::$server->get(\OCP\ServerVersion::class),
      OC::$server->get(\OCP\IConfig::class),
      OC::$server->get(\OCP\EventDispatcher\IEventDispatcher::class),
      new FakeRequest(),
      $this->logger,
      OC::$server->query(MemoryInfo::class),
      OC::$server->get(\OCP\App\IAppManager::class), // Obtain the IAppManager
      OC::$server->get(\OCP\Defaults::class)
    );
    $this->application->setAutoExit(false);
    $this->output = new OccOutput(OutputInterface::VERBOSITY_NORMAL, true);
    $this->application->loadCommands(new StringInput(""), $this->output);    
    $reflectionProperty = new \ReflectionProperty(Application::class, 'application');
    $reflectionProperty->setAccessible(true);
    $this->symphonyApplication = $reflectionProperty->getValue($this->application);
  }

  private function requireAdmin(): ?JSONResponse
  {
    // Deliberate defense-in-depth, not a workaround for a missing framework
    // check: Nextcloud's own SecurityMiddleware already rejects non-admins
    // here by default (no method below has @NoAdminRequired), so this is
    // redundant today. Kept anyway given what this app can do (arbitrary
    // occ/SQL execution) - if the framework check ever regresses, this
    // still holds. If you ever want to intentionally open one of these
    // routes to non-admins, remove the call below AND add
    // @NoAdminRequired, or this will keep silently blocking it.
    //
    // Services via OC::$server so the constructor signature stays unchanged
    // and NC's DI container (without an application.php) can resolve the class.
    $userSession = OC::$server->get(\OCP\IUserSession::class);
    $user = $userSession->getUser();
    if ($user === null) {
      return new JSONResponse(['error' => 'Not authenticated'], 401);
    }
    $groupManager = OC::$server->get(\OCP\IGroupManager::class);
    if (!$groupManager->isAdmin($user->getUID())) {
      return new JSONResponse(['error' => 'Admin privileges required'], 403);
    }
    return null;
  }

  /**
   * @NoCSRFRequired
   */
  public function index()
  {
    if ($err = $this->requireAdmin()) return $err;
    return new TemplateResponse('extended_occweb', 'index');
  }

  /**
   * @param $input
   * @return string
   */
  private function run($input)
  {
    try {
      $this->application->run($input, $this->output);
      return $this->output->fetch();
    } catch (Exception $ex) {
      $this->logger->error($ex->getMessage(), ['exception' => $ex]);
      return "error: " . $ex->getMessage();
    }
  }

  /**
   * @param string $command
   * @return DataResponse
   */
  public function cmd($command)
  {
    if ($err = $this->requireAdmin()) return $err;
    $this->logger->debug($command);
    $input = new StringInput($command);
    $response = $this->run($input);
    $this->logger->debug($response);
    return new DataResponse($response);
  }

  public function list() {
    if ($err = $this->requireAdmin()) return $err;
    $defs = $this->symphonyApplication->all();
    $cmds = array();
    foreach ($defs as $d) {
      array_push($cmds, $d->getName());
    }
    return new DataResponse($cmds);
  }
}

