<?php

namespace OCA\OCCWeb\Controller;

use Exception;
use OC;
use OC\Console\Application;
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
  private $fakeRequest;
  private $symphonyApplication;
  private $output;

  public function __construct($AppName, IRequest $request, $userId)
  {
    parent::__construct($AppName, $request);
    $this->logger = OC::$server->get(LoggerInterface::class);
    $this->userId = $userId;

    $this->fakeRequest = new FakeRequest();
    $this->application = $this->createConsoleApplication($this->fakeRequest);
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
   * Builds Nextcloud's console application with its dependencies from the
   * container, except the request: the console reads argv from it for the
   * ConsoleEvent (used by admin_audit), which a web request doesn't have.
   * Resolving the arguments by type keeps this working when Nextcloud
   * changes the constructor, which it has done several times.
   */
  private function createConsoleApplication(IRequest $request): Application
  {
    $arguments = [];
    foreach ((new \ReflectionClass(Application::class))->getConstructor()->getParameters() as $parameter) {
      $type = $parameter->getType();
      $class = $type instanceof \ReflectionNamedType ? $type->getName() : null;
      $arguments[] = $class === IRequest::class ? $request : OC::$server->get($class);
    }
    return new Application(...$arguments);
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
    } catch (\Throwable $ex) {
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

