<?php

namespace mageekguy\atoum\report\fields\runner\coverage;

require_once __DIR__ . '/../../../../../constants.php';

use
	mageekguy\atoum,
	mageekguy\atoum\locale,
	mageekguy\atoum\report,
	mageekguy\atoum\template,
	mageekguy\atoum\exceptions,
	mageekguy\atoum\cli\prompt,
	mageekguy\atoum\cli\colorizer
;

class html extends report\fields\runner\coverage\cli
{
	const htmlExtensionFile = '.html';

	protected $urlPrompt = null;
	protected $urlColorizer = null;
	protected $php = null;
	protected $adapter = null;
	protected $rootUrl = '';
	protected $projectName = '';
	protected $srcDirectories = array();
	protected $templatesDirectory = null;
	protected $destinationDirectory = null;
	protected $templateParser = null;
	protected $reflectionClassInjector = null;
	protected $bootstrapFile = null;

	public function __construct($projectName, $destinationDirectory)
	{
		parent::__construct();

		$this
			->setProjectName($projectName)
			->setDestinationDirectory($destinationDirectory)
			->setPhp()
			->setAdapter()
			->setUrlPrompt()
			->setUrlColorizer()
			->setTemplatesDirectory()
			->setTemplateParser()
			->setRootUrl('/')
		;
	}

	public function __toString()
	{
		$string = '';

		if (sizeof($this->coverage) > 0)
		{
			try
			{
				$this->cleanDestinationDirectory();

				$this->adapter->copy($this->templatesDirectory . DIRECTORY_SEPARATOR . 'screen.css', $this->destinationDirectory . DIRECTORY_SEPARATOR . 'screen.css');

				$classes = $this->coverage->getClasses();

				$indexTemplate = $this->templateParser->parseFile($this->templatesDirectory . DIRECTORY_SEPARATOR . 'index.tpl');
				$indexTemplate->projectName = $this->projectName;
				$indexTemplate->rootUrl = $this->rootUrl;

				$coverageValue = $this->coverage->getValue();

				if ($coverageValue === null)
				{
					$indexTemplate->coverageUnavailable->build();
				}
				else
				{
					$indexTemplate->coverageAvailable->build(array('coverageValue' => round($coverageValue * 100, 2)));
				}

				$classCoverageTemplates = $indexTemplate->classCoverage;

				$classCoverageAvailableTemplates = $classCoverageTemplates->classCoverageAvailable;
				$classCoverageUnavailableTemplates = $classCoverageTemplates->classCoverageUnavailable;

				ksort($classes, \SORT_STRING);

				foreach ($classes as $className => $classFile)
				{
					$classCoverageTemplates->className = $className;
					$classCoverageTemplates->classUrl = str_replace('\\', '/', $className) . self::htmlExtensionFile;

					$classCoverageValue = $this->coverage->getValueForClass($className);

					$classCoverageAvailableTemplates->build(array('classCoverageValue' => round($classCoverageValue * 100, 2)));

					$classCoverageTemplates->build();

					$classCoverageAvailableTemplates->resetData();
					$classCoverageUnavailableTemplates->resetData();
				}

				$this->adapter->file_put_contents($this->destinationDirectory . DIRECTORY_SEPARATOR . 'index.html', (string) $indexTemplate->build());

				$classTemplate = $this->templateParser->parseFile($this->templatesDirectory . DIRECTORY_SEPARATOR . 'class.tpl');

				$classTemplate->rootUrl = $this->rootUrl;
				$classTemplate->projectName = $this->projectName;

				$classCoverageAvailableTemplates = $classTemplate->classCoverageAvailable;
				$classCoverageUnavailableTemplates = $classTemplate->classCoverageUnavailable;

				$methodsTemplates = $classTemplate->methods;
				$methodTemplates = $methodsTemplates->method;

				$methodCoverageAvailableTemplates = $methodTemplates->methodCoverageAvailable;
				$methodCoverageUnavailableTemplates = $methodTemplates->methodCoverageUnavailable;

				$sourceFileTemplates = $classTemplate->sourceFile;

				$lineTemplates = $sourceFileTemplates->line;
				$coveredLineTemplates = $sourceFileTemplates->coveredLine;
				$notCoveredLineTemplates = $sourceFileTemplates->notCoveredLine;

				foreach ($this->coverage->getMethods() as $className => $methods)
				{
					$classTemplate->className = $className;
					$classTemplate->relativeRootUrl = str_repeat('../', substr_count($className, '\\'));

					$classCoverageValue = $this->coverage->getValueForClass($className);

					if ($classCoverageValue === null)
					{
						$classCoverageUnavailableTemplates->build();
					}
					else
					{
						$classCoverageAvailableTemplates->build(array('classCoverageValue' => round($classCoverageValue * 100, 2)));
					}

					$reflectedMethods = array();

					foreach (array_filter($this->getReflectionClass($className)->getMethods(), function($reflectedMethod) use ($className) { return $reflectedMethod->isAbstract() === false && $reflectedMethod->getDeclaringClass()->getName() === $className; }) as $reflectedMethod)
					{
						$reflectedMethods[$reflectedMethod->getName()] = $reflectedMethod;
					}

					if (sizeof($reflectedMethods) > 0)
					{
						foreach (array_intersect(array_keys($reflectedMethods), array_keys($methods)) as $methodName)
						{
							$methodCoverageValue = $this->coverage->getValueForMethod($className, $methodName);

							if ($methodCoverageValue === null)
							{
								$methodCoverageUnavailableTemplates->build(array('methodName' => $methodName));
							}
							else
							{
								$methodCoverageAvailableTemplates->build(array(
										'methodName' => $methodName,
										'methodCoverageValue' => round($methodCoverageValue * 100, 2)
									)
								);
							}

							$methodTemplates->build();

							$methodCoverageAvailableTemplates->resetData();
							$methodCoverageUnavailableTemplates->resetData();
						}

						$methodsTemplates->build();

						$methodTemplates->resetData();
					}

					$srcFile = $this->adapter->fopen($classes[$className], 'r');

					if ($srcFile !== false)
					{
						$methodLines = array();

						foreach ($reflectedMethods as $reflectedMethodName => $reflectedMethod)
						{
							$methodLines[$reflectedMethod->getStartLine()] = $reflectedMethodName;
						}

						$currentMethod = null;

						for ($currentMethod = null, $lineNumber = 1, $line = $this->adapter->fgets($srcFile); $line !== false; $lineNumber++, $line = $this->adapter->fgets($srcFile))
						{
							if (isset($methodLines[$lineNumber]) === true)
							{
								$currentMethod = $methodLines[$lineNumber];
							}

							switch (true)
							{
								case isset($methods[$currentMethod]) === false || (isset($methods[$currentMethod][$lineNumber]) === false || $methods[$currentMethod][$lineNumber] == -2):
									$lineTemplateName = 'lineTemplates';
									break;

								case isset($methods[$currentMethod]) === true && isset($methods[$currentMethod][$lineNumber]) === true && $methods[$currentMethod][$lineNumber] == -1:
									$lineTemplateName = 'notCoveredLineTemplates';
									break;

								default:
									$lineTemplateName = 'coveredLineTemplates';
							}

							${$lineTemplateName}->lineNumber = $lineNumber;
							${$lineTemplateName}->code = htmlentities($line, ENT_QUOTES, 'UTF-8');

							if (isset($methodLines[$lineNumber]) === true)
							{
								foreach (${$lineTemplateName}->anchor as $anchorTemplate)
								{
									$anchorTemplate->resetData();
									$anchorTemplate->method = $currentMethod;
									$anchorTemplate->build();
								}
							}

							${$lineTemplateName}
								->addToParent()
								->resetData()
							;
						}

						$this->adapter->fclose($srcFile);
					}

					$file = $this->destinationDirectory . DIRECTORY_SEPARATOR . str_replace('\\', '/', $className) . self::htmlExtensionFile;

					$directory = $this->adapter->dirname($file);

					if ($this->adapter->is_dir($directory) === false)
					{
						$this->adapter->mkdir($directory, 0777, true);
					}

					$this->adapter->file_put_contents($file, (string) $classTemplate->build());

					$classTemplate->resetData();

					$classCoverageAvailableTemplates->resetData();
					$classCoverageUnavailableTemplates->resetData();

					$methodsTemplates->resetData();

					$sourceFileTemplates->resetData();
				}

				$string .= $this->urlPrompt . $this->urlColorizer->colorize(sprintf($this->locale->_('Details of code coverage are available at %s.'), $this->rootUrl)) . PHP_EOL;
			}
			catch (\exception $exception)
			{
				$string .= $this->urlPrompt . $this->urlColorizer->colorize(sprintf($this->locale->_('Unable to generate code coverage at %s: %s.'), $this->rootUrl, $exception->getMessage())) . PHP_EOL;
			}
		}

		return parent::__toString() . $string;
	}

	public function setPhp(atoum\php $php = null)
	{
		$this->php = $php ?: new atoum\php();

		return $this;
	}

	public function getPhp()
	{
		return $this->php;
	}

	public function setReflectionClassInjector(\closure $reflectionClassInjector)
	{
		$closure = new \reflectionMethod($reflectionClassInjector, '__invoke');

		if ($closure->getNumberOfParameters() != 1)
		{
			throw new exceptions\logic\invalidArgument('Reflection class injector must take one argument');
		}

		$this->reflectionClassInjector = $reflectionClassInjector;

		return $this;
	}

	public function getReflectionClass($class)
	{
		$reflectionClass = null;

		if ($this->reflectionClassInjector === null)
		{
			$reflectionClass = new \reflectionClass($class);
		}
		else
		{
			$reflectionClass = $this->reflectionClassInjector->__invoke($class);

			if ($reflectionClass instanceof \reflectionClass === false)
			{
				throw new exceptions\runtime\unexpectedValue('Reflection class injector must return a \reflectionClass instance');
			}
		}

		return $reflectionClass;
	}

	public function setProjectName($projectName)
	{
		$this->projectName = (string) $projectName;

		return $this;
	}

	public function getProjectName()
	{
		return $this->projectName;
	}

	public function setDestinationDirectory($path)
	{
		$this->destinationDirectory = (string) $path;

		return $this;
	}

	public function getDestinationDirectory()
	{
		return $this->destinationDirectory;
	}

	public function setAdapter(atoum\adapter $adapter = null)
	{
		$this->adapter = $adapter ?: new atoum\adapter();

		return $this;
	}

	public function getAdapter()
	{
		return $this->adapter;
	}

	public function setUrlPrompt(prompt $prompt = null)
	{
		$this->urlPrompt = $prompt ?: new prompt();

		return $this;
	}

	public function getUrlPrompt()
	{
		return $this->urlPrompt;
	}

	public function setUrlColorizer(colorizer $colorizer = null)
	{
		$this->urlColorizer = $colorizer ?: new colorizer();

		return $this;
	}

	public function getUrlColorizer()
	{
		return $this->urlColorizer;
	}

	public function setTemplatesDirectory($path = null)
	{
		$this->templatesDirectory = ($path !== null ? (string) $path : atoum\directory . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'coverage');

		return $this;
	}

	public function getTemplatesDirectory()
	{
		return $this->templatesDirectory;
	}

	public function setTemplateParser(template\parser $parser = null)
	{
		$this->templateParser = $parser ?: new template\parser();

		return $this;
	}

	public function getTemplateParser()
	{
		return $this->templateParser;
	}

	public function setRootUrl($rootUrl)
	{
		$this->rootUrl = rtrim((string) $rootUrl, '/') . '/';

		return $this;
	}

	public function getRootUrl()
	{
		return $this->rootUrl;
	}

	public function addSrcDirectory($srcDirectory, \closure $filterClosure = null)
	{
		$srcDirectory = (string) $srcDirectory;

		if (isset($this->srcDirectories[$srcDirectory]) === false)
		{
			$this->srcDirectories[$srcDirectory] = $filterClosure === null ? array() : array($filterClosure);
		}
		else if ($filterClosure !== null)
		{
			$this->srcDirectories[$srcDirectory][] = $filterClosure;
		}

		return $this;
	}

	public function getSrcDirectories()
	{
		return $this->srcDirectories;
	}

	public function getDestinationDirectoryIterator()
	{
		return new \recursiveIteratorIterator(new \recursiveDirectoryIterator($this->destinationDirectory, \filesystemIterator::KEY_AS_PATHNAME | \filesystemIterator::CURRENT_AS_FILEINFO | \filesystemIterator::SKIP_DOTS), \recursiveIteratorIterator::CHILD_FIRST);
	}

	public function getSrcDirectoryIterators()
	{
		$iterators = array();

		foreach ($this->srcDirectories as $srcDirectory => $closures)
		{
			$iterators[] = $iterator = new \recursiveIteratorIterator(new atoum\iterators\filters\recursives\closure(new \recursiveDirectoryIterator($srcDirectory, \filesystemIterator::SKIP_DOTS|\filesystemIterator::CURRENT_AS_FILEINFO)), \recursiveIteratorIterator::LEAVES_ONLY);

			foreach ($closures as $closure)
			{
				$iterator->addClosure($closure);
			}
		}

		return $iterators;
	}

	public function cleanDestinationDirectory()
	{
		try
		{
			foreach ($this->getDestinationDirectoryIterator() as $inode)
			{
				if ($inode->isDir() === false)
				{
					$this->adapter->unlink($inode->getPathname());
				}
				else if (($pathname = $inode->getPathname()) !== $this->destinationDirectory)
				{
					$this->adapter->rmdir($pathname);
				}
			}
		}
		catch (\exception $exception) {}

		return $this;
	}

	public function handleEvent($event, \mageekguy\atoum\observable $observable)
	{
		if (parent::handleEvent($event, $observable) === false)
		{
			return false;
		}
		else
		{
			if ($this->adapter->extension_loaded('xdebug') === true)
			{
				$phpCode =
					'<?php ' .
					'ob_start();' .
					'require \'' . atoum\directory . '/classes/autoloader.php\';'
				;

				$bootstrapFile = $observable->getBootstrapFile();

				if ($bootstrapFile !== null)
				{
					$phpCode .=
						'$includer = new mageekguy\atoum\includer();' .
						'try { $includer->includePath(\'' . $bootstrapFile . '\'); }' .
						'catch (mageekguy\atoum\includer\exception $exception)' .
						'{ die(\'Unable to include bootstrap file \\\'' . $bootstrapFile . '\\\'\'); }'
					;
				}

				$phpCode .=
					'ob_start();' .
					'xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);' .
					'$declaredClasses = get_declared_classes();' .
					'require_once \'%s\';' .
					'$xDebugData = xdebug_get_code_coverage();' .
					'xdebug_stop_code_coverage();' .
					'ob_end_clean();' .
					'$classes = array_diff(get_declared_classes(), $declaredClasses);' .
					'echo serialize(array(\'classes\' => $classes, \'coverage\' => $xDebugData));'
				;

				foreach ($this->getSrcDirectoryIterators() as $srcDirectoryIterator)
				{
					foreach ($srcDirectoryIterator as $file)
					{
						if (in_array($file->getPathname(), $this->adapter->get_included_files()) === false)
						{
							$exitCode = $this->php->reset()->execute(sprintf($phpCode, $file->getPathname()))->getExitCode();

							if ($exitCode > 0)
							{
								throw new exceptions\runtime('Unable to get default code coverage for file \'' . $file->getPathname() . '\': ' . $this->php->getStderr());
							}

							$data = unserialize($this->php->getStdOut());

							foreach ($data['classes'] as $class)
							{
								$this->coverage->addXdebugDataForClass($class, $data['coverage']);
							}
						}
					}
				}
			}

			return true;
		}
	}
}
