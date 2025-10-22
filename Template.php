<?php
/**
 * Template Class
 *
 * @category  Template
 * @package   Template
 * @author    Osman Cakmak <info@oxcakmak.com>
 * @copyright Copyright (c) 2024-?
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      https://github.com/oxcakmak/PHP-Template-Class
 * @version   1.1.7
 * @other     special thanks to:
 *            Berat Kara <@beratkara - https://www.beratkara.com>
 */
class Template {
    /** @var array Variables available to templates */
    private $variables = [];
    
    /** @var string Directory containing template files */
    private $templateDir;
    
    /** @var string File extension for template files */
    private $templateExt;
    
    /** @var int Maximum memory usage allowed (256MB default) */
    private $maxMemory = 268435456;
    
    /** @var int Maximum iterations for recursive template processing */
    private $maxIterations = 10;
    
    /** @var string Error handling mode: 'comment', 'exception', or 'silent' */
    private $errorMode = 'comment';
    
    /** @var bool Enable debug mode for detailed error messages */
    private $debugMode = false;
    
    /**
     * Constructor
     *
     * @param string $templateDir Directory containing template files
     * @param string $templateExt File extension for template files (default: html)
     * @param array $options Additional options for template engine
     * @throws Exception If template directory is not specified or does not exist
     */
    public function __construct($templateDir, $templateExt = 'html', $options = []) {
        if (empty($templateDir)) {
            throw new Exception("Template directory must be specified");
        }
        
        // Ensure template directory ends with a directory separator
        if (substr($templateDir, -1) !== DIRECTORY_SEPARATOR) {
            $templateDir .= DIRECTORY_SEPARATOR;
        }
        
        // Check if template directory exists
        if (!is_dir($templateDir)) {
            throw new Exception("Template directory does not exist: {$templateDir}");
        }
        
        $this->templateDir = $templateDir;
        $this->templateExt = $templateExt;
        
        // Set options if provided
        if (isset($options['maxMemory'])) {
            $this->maxMemory = (int)$options['maxMemory'];
        }
        
        if (isset($options['maxIterations'])) {
            $this->maxIterations = (int)$options['maxIterations'];
        }
        
        if (isset($options['errorMode']) && in_array($options['errorMode'], ['comment', 'exception', 'silent'])) {
            $this->errorMode = $options['errorMode'];
        }
        
        if (isset($options['debugMode'])) {
            $this->debugMode = (bool)$options['debugMode'];
        }
    }
    
    /**
     * Assign a variable to the template
     *
     * @param string $name Variable name
     * @param mixed $value Variable value
     * @return Template For method chaining
     */
    public function assign($name, $value) {
        $this->variables[$name] = $value;
        return $this;
    }
    
    /**
     * Assign multiple variables to the template
     *
     * @param array $variables Associative array of variables
     * @return Template For method chaining
     */
    public function assignMultiple($variables) {
        if (is_array($variables)) {
            foreach ($variables as $name => $value) {
                $this->variables[$name] = $value;
            }
        }
        return $this;
    }
    
    /**
     * Load and process a template file
     *
     * @param string $templateName Name of the template file (without extension)
     * @return string Processed template content
     * @throws Exception If template file does not exist
     */
    public function load($templateName) {
        $templatePath = $this->templateDir . $templateName . '.' . $this->templateExt;
        
        if (!file_exists($templatePath)) {
            throw new Exception("Template file not found: {$templatePath}");
        }
        
        $content = file_get_contents($templatePath);
        return $this->processTemplate($content);
    }
    
    /**
     * Process template content
     *
     * @param string $template Template content to process
     * @return string Processed template content
     */
    private function processTemplate($template) {
        $iteration = 0;
        $lastTemplate = '';
        
        while ($template !== $lastTemplate && $iteration < $this->maxIterations) {
            $this->checkMemoryUsage();
            
            $lastTemplate = $template;
            
            // Process includes first
            $template = preg_replace_callback(
                '/\{\{\s*inc\(([^)]+)\)\s*\}\}/',
                array($this, 'processFunction'),
                $template
            );
            
            // Process function calls with parameters - FIX HERE
            $template = preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_]+)\s*\(\s*(.*?)\s*\)\s*\}\}/s',
                array($this, 'processFunctionCall'),
                $template
            );
            
            // Process for loops
            $template = $this->processForLoops($template);
            
            // Process if conditions
            $template = $this->processIfConditions($template);
            
            // Process variables
            $template = $this->processVariablesInContent($template);
            
            $iteration++;
        }
        
        return $this->cleanupTemplate($template);
    }
    
    /**
     * Process for loops in templates
     *
     * @param string $template Template content to process
     * @return string Processed template content
     */
    private function processForLoops($template) {
        $offset = 0;

        while (($start = strpos($template, '{% for', $offset)) !== false) {
            $stack = [$start];
            $pos = $start + 1;

            while (!empty($stack)) {
                $nextFor = strpos($template, '{% for', $pos);
                $nextEndfor = strpos($template, '{% endfor %}', $pos);

                if ($nextEndfor === false) {
                    return $template;
                }

                if ($nextFor !== false && $nextFor < $nextEndfor) {
                    $stack[] = $nextFor;
                    $pos = $nextFor + 1;
                } else {
                    array_pop($stack);
                    $pos = $nextEndfor + strlen('{% endfor %}');
                }
            }

            $block = substr($template, $start, $pos - $start);

            if (preg_match('/\{%\s*for\s+([a-zA-Z0-9_]+)\s*,\s*([a-zA-Z0-9_]+)\s+in\s+([^\s%]+)\s*%\}([\s\S]*)\{%\s*endfor\s*%\}/', $block, $matches)) {
                $replacement = $this->processKeyValueForLoop($matches);
            } elseif (preg_match('/\{%\s*for\s+([a-zA-Z0-9_]+)\s+in\s+([^\s%]+)\s*%\}([\s\S]*)\{%\s*endfor\s*%\}/', $block, $matches)) {
                $replacement = $this->processForLoop($matches);
            } else {
                return $template;
            }

            $template = substr_replace($template, $replacement, $start, $pos - $start);
            $offset = $start + strlen($replacement);
        }

        return $template;
    }
    
    /**
     * Process a regular for loop
     *
     * @param array $matches Regex matches from preg_replace_callback
     * @return string The processed loop content
     */
    private function processForLoop($matches) {
        $itemName = $matches[1];
        $arrayPath = $matches[2];
        $content = $matches[3];
        
        // Get the array to iterate over
        $array = $this->getNestedValue($arrayPath);
        
        // If not an array or empty, return appropriate message
        if (!is_array($array)) {
            if ($this->debugMode) {
                return "<!-- Debug: Array '{$arrayPath}' not found or not an array -->";
            }
            return '';
        }
        
        $result = '';
        $originalVars = $this->variables;
        $arrayLength = count($array);
        $index = 0;
        
        // Store original loop variable if it exists (for nested loops)
        $originalLoop = isset($this->variables['loop']) ? $this->variables['loop'] : null;
        
        foreach ($array as $key => $item) {
            $this->checkMemoryUsage();
            
            // Create a new scope for this iteration
            $iterationVars = $originalVars;
            
            // Set the item variable in the current iteration's scope
            $iterationVars[$itemName] = $item;
            
            // Add loop metadata
            $iterationVars['loop'] = [
                'index' => $index + 1,
                'index0' => $index,
                'first' => ($index === 0),
                'last' => ($index === $arrayLength - 1),
                'length' => $arrayLength,
                'parent' => $originalLoop
            ];
            
            // Set the current scope for variable replacement
            $this->variables = $iterationVars;
            
            // Process the content for this iteration
            $processedContent = $this->processTemplate($content);
            $result .= $processedContent;
            
            $index++;
        }
        
        // Restore original variables scope
        $this->variables = $originalVars;
        
        return $result;
    }

    /**
     * Helper method to get a property from an array using a dot notation path
     *
     * @param array $array The array to get the property from
     * @param string $path The path to the property (dot notation)
     * @return mixed The property value or null if not found
     */
    private function getPropertyFromArray($array, $path) {
        $parts = explode('.', $path);
        $current = $array;
        
        foreach ($parts as $part) {
            if (empty($part)) continue;
            
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                return null;
            }
        }
        
        return $current;
    }
    
    /**
     * Process a key-value for loop
     *
     * @param array $matches Regex matches from preg_replace_callback
     * @return string The processed loop content
     */
    private function processKeyValueForLoop($matches) {
        $keyName = $matches[1];
        $valueName = $matches[2];
        $arrayPath = $matches[3];
        $content = $matches[4];
        
        // Get the array to iterate over
        $array = $this->getNestedValue($arrayPath);
        
        // If not an array or empty, return appropriate message
        if (!is_array($array)) {
            if ($this->debugMode) {
                return "<!-- Debug: Array '{$arrayPath}' not found or not an array -->";
            }
            return '';
        }
        
        $result = '';
        $originalVars = $this->variables;
        $arrayLength = count($array);
        $index = 0;
        
        // Store original loop variable if it exists (for nested loops)
        $originalLoop = isset($this->variables['loop']) ? $this->variables['loop'] : null;
        
        foreach ($array as $key => $value) {
            $this->checkMemoryUsage();
            
            // Create new scope for this iteration
            $iterationVars = $originalVars;
            $iterationVars[$keyName] = $key;
            $iterationVars[$valueName] = $value;
            
            // Add loop metadata
            $iterationVars['loop'] = [
                'index' => $index + 1,
                'index0' => $index,
                'first' => ($index === 0),
                'last' => ($index === $arrayLength - 1),
                'length' => $arrayLength,
                'parent' => $originalLoop
            ];
            
            // Set the current scope for variable replacement
            $this->variables = $iterationVars;
            
            // Process the content for this iteration
            $processedContent = $this->processTemplate($content);
            $result .= $processedContent;
            
            $index++;
        }
        
        // Restore original variables scope
        $this->variables = $originalVars;
        
        return $result;
    }
    
    /**
     * Process a loop with an array
     *
     * @param array $array The array to iterate over
     * @param string $itemName The name of the item variable
     * @param string $arrayPath The path to the array (for debugging)
     * @param string $content The content to process for each iteration
     * @return string The processed content
     */
    private function processLoop($array, $itemName, $arrayPath, $content) {
        $result = '';
        $originalVars = $this->variables; // Backup original scope
        $arrayLength = count($array);
        $index = 0;
        
        foreach ($array as $key => $item) {
            $this->checkMemoryUsage();
            
            // Create new scope for this iteration
            $iterationVars = $originalVars;
            $iterationVars[$itemName] = $item;
            
            // Add loop metadata
            $iterationVars['loop'] = [
                'index' => $index + 1,
                'first' => ($index === 0),
                'last' => ($index === $arrayLength - 1),
                'length' => $arrayLength,
                'parent' => isset($originalVars['loop']) ? $originalVars['loop'] : null
            ];
            
            // Set the current scope for variable replacement
            $this->variables = $iterationVars;
            
            // Process the content for this iteration
            $processedContent = $content;
            
            // Process nested loops first
            $processedContent = $this->processForLoops($processedContent);
            
            // Process if conditions
            $processedContent = $this->processIfConditions($processedContent);
            
            // Process variables
            $processedContent = $this->processVariablesInContent($processedContent);
            
            $result .= $processedContent;
            
            $index++;
        }
        
        // Restore original variables scope
        $this->variables = $originalVars;
        
        return $result;
    }

    /**
     * Get a property from an item (array or object)
     *
     * @param mixed $item The item to get the property from
     * @param string $property The property path (e.g. "colors.0", "features")
     * @return mixed The property value
     */
    private function getPropertyFromItem($item, $property) {
        $parts = explode('.', $property);
        $current = $item;
        
        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } elseif (is_object($current) && property_exists($current, $part)) {
                $current = $current->$part;
            } else {
                if ($this->debugMode) {
                    $type = is_object($current) ? get_class($current) : gettype($current);
                    error_log("Template Debug: Property '{$part}' not found in {$type}");
                }
                return null;
            }
        }
        
        return $current;
    }

    /**
     * Process a key-value loop (for key, value in array)
     */
    private function processKeyValueLoop($array, $keyName, $valueName, $arrayPath, $content) {
        $result = '';
        $originalVars = $this->variables; // Backup original scope
        $arrayLength = count($array);
        $index = 0;
        
        foreach ($array as $key => $value) {
            $this->checkMemoryUsage();
            
            // Create new scope for this iteration
            $iterationVars = $originalVars;
            $iterationVars[$keyName] = $key;
            $iterationVars[$valueName] = $value;
            
            // Add loop metadata
            $iterationVars['loop'] = [
                'index' => $index + 1,
                'first' => ($index === 0),
                'last' => ($index === $arrayLength - 1),
                'length' => $arrayLength,
                'parent' => isset($originalVars['loop']) ? $originalVars['loop'] : null
            ];
            
            // Set the current scope for variable replacement
            $this->variables = $iterationVars;
            
            // Process the content for this iteration
            $processedContent = $content;
            
            // Process nested loops first
            $processedContent = $this->processForLoops($processedContent);
            
            // Process if conditions
            $processedContent = $this->processIfConditions($processedContent);
            
            // Process variables
            $processedContent = $this->processVariablesInContent($processedContent);
            
            $result .= $processedContent;
            
            $index++;
        }
        
        // Restore original variables scope
        $this->variables = $originalVars;
        
        return $result;
    }
    
    /**
     * Process if conditions in templates
     *
     * @param string $template The template to process
     * @return string The processed template
     */
    private function processIfConditions($template) {
        $iteration = 0;
        $lastTemplate = '';
        
        while ($template !== $lastTemplate && $iteration < $this->maxIterations) {
            $this->checkMemoryUsage();
            
            $lastTemplate = $template;
            
            // First, process if-else blocks (simpler case)
            $template = preg_replace_callback(
                '/\{%\s*if\s+(.+?)\s*%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endif\s*%\}/s',
                array($this, 'processSimpleCondition'),
                $template
            );
            
            // Then process if-elseif-else blocks (more complex case)
            $template = preg_replace_callback(
                '/\{%\s*if\s+(.+?)\s*%\}(.*?)(?:\{%\s*elseif\s+(.+?)\s*%\}(.*?))*(?:\{%\s*else\s*%\}(.*?))?\{%\s*endif\s*%\}/s',
                array($this, 'processComplexCondition'),
                $template
            );
            
            $iteration++;
        }
        
        return $template;
    }
    
    /**
     * Process a simple if-else condition
     *
     * @param array $matches Regex matches from preg_replace_callback
     * @return string The processed content based on the condition
     */
    private function processSimpleCondition($matches) {
        $condition = $matches[1];
        $ifContent = isset($matches[2]) ? $matches[2] : '';
        $elseContent = isset($matches[3]) ? $matches[3] : '';
        
        try {
            $result = $this->evaluateCondition($condition);
            
            if ($result) {
                return $this->processTemplate($ifContent);
            } else {
                return $this->processTemplate($elseContent);
            }
        } catch (Exception $e) {
            if ($this->errorMode === 'exception') {
                throw $e;
            } elseif ($this->errorMode === 'comment') {
                return "<!-- Error processing condition '{$condition}': " . 
                       htmlspecialchars($e->getMessage()) . " -->";
            }
            return '';
        }
    }
    
    /**
     * Process a complex if-elseif-else condition
     *
     * @param array $matches Regex matches from preg_replace_callback
     * @return string The processed content based on the conditions
     */
    private function processComplexCondition($matches) {
        $ifCondition = $matches[1];
        $ifContent = isset($matches[2]) ? $matches[2] : '';
        
        try {
            // Check if condition
            if ($this->evaluateCondition($ifCondition)) {
                return $this->processTemplate($ifContent);
            }
            
            // Check for elseif conditions
            $fullMatch = $matches[0];
            if (preg_match_all('/\{%\s*elseif\s+(.+?)\s*%\}(.*?)(?=\{%\s*(?:elseif|else|endif)\s*%\})/s', $fullMatch, $elseifMatches, PREG_SET_ORDER)) {
                foreach ($elseifMatches as $elseifMatch) {
                    $elseifCondition = $elseifMatch[1];
                    $elseifContent = $elseifMatch[2];
                    
                    if ($this->evaluateCondition($elseifCondition)) {
                        return $this->processTemplate($elseifContent);
                    }
                }
            }
            
            // Check for else content
            if (preg_match('/\{%\s*else\s*%\}(.*?)(?=\{%\s*endif\s*%\})/s', $fullMatch, $elseMatch)) {
                $elseContent = $elseMatch[1];
                return $this->processTemplate($elseContent);
            }
            
            return '';
        } catch (Exception $e) {
            if ($this->errorMode === 'exception') {
                throw $e;
            } elseif ($this->errorMode === 'comment') {
                return "<!-- Error processing complex condition: " . 
                       htmlspecialchars($e->getMessage()) . " -->";
            }
            return '';
        }
    }
    
    /**
     * Evaluate a condition expression
     *
     * @param string $condition The condition to evaluate
     * @return bool The result of the evaluation
     */
    private function evaluateCondition($condition) {
        // Replace variables in the condition with their values
        $condition = preg_replace_callback(
            '/([a-zA-Z0-9._\[\]]+)/',
            array($this, 'replaceConditionVariable'),
            $condition
        );
        
        // Replace operators for PHP evaluation
        $condition = str_replace('===', '==', $condition);
        $condition = str_replace('!==', '!=', $condition);
        
        // Convert logical operators to PHP syntax
        $condition = str_replace(' and ', ' && ', $condition);
        $condition = str_replace(' or ', ' || ', $condition);
        $condition = str_replace(' not ', ' !', $condition);
        
        // Convert boolean literals to PHP syntax
        $condition = str_replace(' true', ' true', $condition);
        $condition = str_replace(' false', ' false', $condition);
        
        // Evaluate the condition safely
        try {
            // Add error suppression to prevent warnings
            $result = @eval("return (bool)($condition);");
            
            // If eval failed, return false
            if ($result === false && error_get_last() !== null) {
                if ($this->debugMode) {
                    error_log("Template Debug: Failed to evaluate condition: '{$condition}'");
                }
                return false;
            }
            
            return $result;
        } catch (Exception $e) {
            if ($this->debugMode) {
                error_log("Template Debug: Error evaluating condition '{$condition}': " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Replace variables in condition expressions
     *
     * @param array $matches Regex matches from preg_replace_callback
     * @return string The value of the variable or the original string if not a variable
     */
    private function replaceConditionVariable($matches) {
        $path = $matches[1];
        
        // Skip operators and literals
        $operators = ['and', 'or', 'not', 'true', 'false', 'null'];
        if (is_numeric($path) || in_array(strtolower($path), $operators)) {
            return $path;
        }
        
        // Handle string literals
        if (preg_match('/^["\'].*["\']$/', $path)) {
            return $path;
        }
        
        // Get the value of the variable
        $value = $this->getNestedValue($path);
        
        // Handle null values
        if ($value === null) {
            return null;
        }
        
        // Handle boolean values
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        // Handle numeric values
        if (is_numeric($value)) {
            return $value;
        }
        
        // Handle string values
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        
        // Handle array values
        if (is_array($value)) {
            return !empty($value) ? 'true' : 'false';
        }
        
        // Handle object values
        if (is_object($value)) {
            return 'true';
        }
        
        return null;
    }
    
    /**
     * Helper method to process variables in content
     */
    private function processVariablesInContent($content) {
        // Process variables with bracket notation first
        $content = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+(?:\[[^\]]+\])+(?:\.[a-zA-Z0-9_]+)*)\s*\}\}/',
            array($this, 'replaceVariable'),
            $content
        );
        
        // Process regular variables (must come after bracket notation)
        $content = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9._]+)\s*\}\}/',
            array($this, 'replaceVariable'),
            $content
        );
        
        return $content;
    }
    
    /**
     * Replace a variable with its value
     *
     * @param array $matches Regex matches from preg_replace_callback
     * @return string The value of the variable
     */
    private function replaceVariable($matches) {
        $path = $matches[1];

        // Special case for .length
        if (preg_match('/^([a-zA-Z0-9_\.]+)\.length$/', $path, $lenMatch)) {
            $arr = $this->getNestedValue($lenMatch[1]);
            if (is_array($arr)) {
                return count($arr);
            } else {
                if ($this->debugMode) {
                    return "<!-- Debug: Variable '{$path}' not found -->";
                }
                return '';
            }
        }
        
        try {
            // Handle direct variable access first (no dots)
            if (strpos($path, '.') === false && array_key_exists($path, $this->variables)) {
                $value = $this->variables[$path];
                
                // Prevent direct array output
                if (is_array($value)) {
                    if ($this->debugMode) {
                        return "<!-- Debug: Cannot directly output array '{$path}'. Use a for loop instead. -->";
                    }
                    return '';
                }
                
                return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            }
            
            // Handle nested properties
            $value = $this->getNestedValue($path);
            
            // Handle null values
            if ($value === null) {
                if ($this->debugMode) {
                    return "<!-- Debug: Variable '{$path}' not found -->";
                }
                return '';
            }
            
            // Prevent direct array output
            if (is_array($value)) {
                if ($this->debugMode) {
                    return "<!-- Debug: Cannot directly output array '{$path}'. Use a for loop instead. -->";
                }
                return '';
            }
            
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        } catch (Exception $e) {
            if ($this->debugMode) {
                return '<!-- Error processing variable ' . htmlspecialchars($path) . ': ' . 
                       htmlspecialchars($e->getMessage()) . ' -->';
            }
            return '';
        }
    }
    
    /**
     * Get a nested value from the variables array using dot and bracket notation
     *
     * @param string $path The path to the value (e.g. "user.details.balance" or "user[details][balance]")
     * @return mixed The value at the path or null if not found
     */
    private function getNestedValue($path) {
        try {
            // Handle empty path
            if (empty($path)) {
                return null;
            }
            
            // Normalize path by handling mixed notation (brackets and dots)
            $path = preg_replace_callback(
                '/\[([^\[\]]+)\]/', 
                function($match) {
                    return '.' . $match[1];
                }, 
                $path
            );
            
            // Split the path into parts
            $parts = explode('.', $path);
            $firstPart = array_shift($parts);
            
            // Check if the first part exists in current variables scope
            if (!array_key_exists($firstPart, $this->variables)) {
                return null;
            }
            
            // Start with the first part from current variables scope
            $current = $this->variables[$firstPart];
            
            // Traverse the path
            foreach ($parts as $part) {
                if (empty($part)) continue;
                
                // Handle array access
                if (is_array($current) && array_key_exists($part, $current)) {
                    $current = $current[$part];
                } 
                // Handle object access
                elseif (is_object($current) && property_exists($current, $part)) {
                    $current = $current->$part;
                } 
                // Handle numeric array access
                elseif (is_array($current) && is_numeric($part) && isset($current[(int)$part])) {
                    $current = $current[(int)$part];
                } 
                else {
                    return null;
                }
            }
            
            return $current;
        } catch (Exception $e) {
            if ($this->debugMode) {
                error_log("Template Debug: Error getting nested value for '{$path}': " . $e->getMessage());
            }
            return null;
        }
    }
    
    /**
     * Process include statements in templates
     *
     * @param array $matches Regex matches from preg_replace_callback
     * @return string The processed include content
     */
    private function processFunction($matches) {
        $functionName = 'inc';
        $arguments = $matches[1];
        
        // Currently only supporting the inc() function
        if ($functionName === 'inc') {
            // Extract the template name from the arguments
            if (preg_match('/[\'"]([^\'"]+)[\'"]/', $arguments, $argMatches)) {
                return $this->processInclude([0, $argMatches[1]]);
            }
        }
        
        if ($this->debugMode) {
            return "<!-- Unknown function: {$functionName}() -->";
        }
        return '';
    }
    
    /**
     * Process include statements in templates
     *
     * @param array $matches Regex matches from preg_replace_callback
     * @return string The processed include content
     */
    private function processInclude($matches) {
        $includeName = $matches[1];
        $includePath = $this->templateDir . $includeName . '.' . $this->templateExt;
        
        if (!file_exists($includePath)) {
            if ($this->debugMode) {
                return "<!-- Include file not found: {$includePath} -->";
            }
            return '';
        }
        
        try {
            $includeContent = file_get_contents($includePath);
            
            // Process the included template with the current variables
            return $this->processTemplate($includeContent);
        } catch (Exception $e) {
            if ($this->errorMode === 'exception') {
                throw $e;
            } elseif ($this->errorMode === 'comment') {
                return "<!-- Error processing include '{$includeName}': " . 
                       htmlspecialchars($e->getMessage()) . " -->";
            }
            return '';
        }
    }
    
    /**
     * Clean up the template by removing any remaining template tags and extra whitespace
     *
     * @param string $template The template to clean up
     * @return string The cleaned template
     */
    private function cleanupTemplate($template) {
        // Remove any remaining template tags (for safety)
        $template = preg_replace('/\{%.*?%\}/', '', $template);
        
        // Optionally, you could also remove extra whitespace here
        // $template = preg_replace('/\s+/', ' ', $template);
        
        return $template;
    }
    
    /**
     * Check if memory usage is approaching the limit and throw an exception if necessary
     *
     * @throws Exception if memory usage exceeds the limit
     */
    private function checkMemoryUsage() {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->maxMemory;
        
        // If memory usage is over 90% of the limit, throw an exception
        if ($memoryUsage > ($memoryLimit * 0.9)) {
            throw new Exception("Memory usage limit approaching: {$memoryUsage} bytes used of {$memoryLimit} bytes allowed");
        }
    }
    
    /**
     * Set debug mode
     *
     * @param bool $debugMode
     * @return Template For method chaining
     */
    public function setDebugMode($debugMode) {
        $this->debugMode = $debugMode;
        return $this;
    }
    
    /**
     * Set error mode
     *
     * @param string $errorMode 'comment', 'exception', or 'silent'
     * @return Template For method chaining
     */
    public function setErrorMode($errorMode) {
        if (in_array($errorMode, ['comment', 'exception', 'silent'])) {
            $this->errorMode = $errorMode;
        }
        return $this;
    }
    
    /**
     * Set maximum memory usage
     *
     * @param int $maxMemory Maximum memory usage in bytes
     * @return Template For method chaining
     */
    public function setMaxMemory($maxMemory) {
        $this->maxMemory = (int)$maxMemory;
        return $this;
    }
    
    /**
     * Set maximum iterations for recursive template processing
     *
     * @param int $maxIterations Maximum iterations
     * @return Template For method chaining
     */
    public function setMaxIterations($maxIterations) {
        $this->maxIterations = (int)$maxIterations;
        return $this;
    }

    /**
     * Process function calls with parameters
     *
     * @param array $matches Regex matches from preg_replace_callback
     * @return string The result of the function call
     */
    private function processFunctionCall($matches) {
        $functionName = $matches[1];
        $argsString = $matches[2];
        
        // List of allowed functions for security
        $allowedFunctions = [
            'htmlspecialchars', 'htmlentities', 'strip_tags',
            'strtoupper', 'strtolower', 'ucfirst', 'lcfirst', 'ucwords',
            'number_format', 'round', 'floor', 'ceil', 'abs',
            'count', 'sizeof', 'implode', 'explode', 'trim', 'ltrim', 'rtrim',
            'date', 'time', 'strtotime', 'nl2br', 'json_encode', 'md5', 'sha1',
            'isset', 'empty', 'is_array', 'is_string', 'is_numeric', 'is_object'
        ];
        
        // Check if function is allowed
        if (!in_array($functionName, $allowedFunctions)) {
            if ($this->debugMode) {
                return "<!-- Function '{$functionName}' is not allowed -->";
            }
            return '';
        }
        
        // Parse arguments
        $args = $this->parseFunctionArguments($argsString);
        
        // Call the function
        try {
            $result = call_user_func_array($functionName, $args);
            
            // Convert result to string
            if (is_array($result) || is_object($result)) {
                return json_encode($result);
            } elseif (is_bool($result)) {
                return $result ? 'true' : 'false';
            } elseif (is_null($result)) {
                return '';
            } else {
                return (string)$result;
            }
        } catch (Exception $e) {
            if ($this->debugMode) {
                return "<!-- Error calling function '{$functionName}': " . 
                    htmlspecialchars($e->getMessage()) . " -->";
            }
            return '';
        }
    }

    /**
     * Parse function arguments
     *
     * @param string $argsString The arguments string
     * @return array The parsed arguments
     */
    private function parseFunctionArguments($argsString) {
        $args = [];
        
        // If no arguments, return empty array
        if (empty(trim($argsString))) {
            return $args;
        }
        
        // Split by commas, but respect quotes
        $inQuote = false;
        $quoteChar = '';
        $currentArg = '';
        $escaped = false;
        
        for ($i = 0; $i < strlen($argsString); $i++) {
            $char = $argsString[$i];
            
            // Handle escape character
            if ($char === '\\' && !$escaped) {
                $escaped = true;
                continue;
            }
            
            // Handle quotes
            if (($char === '"' || $char === "'") && !$escaped) {
                if (!$inQuote) {
                    $inQuote = true;
                    $quoteChar = $char;
                } elseif ($char === $quoteChar) {
                    $inQuote = false;
                } else {
                    $currentArg .= $char;
                }
            }
            // Handle comma (argument separator)
            elseif ($char === ',' && !$inQuote) {
                $args[] = $this->processArgumentValue(trim($currentArg));
                $currentArg = '';
            }
            // All other characters
            else {
                $currentArg .= $char;
            }
            
            $escaped = false;
        }
        
        // Add the last argument
        if (!empty($currentArg) || count($args) > 0) {
            $args[] = $this->processArgumentValue(trim($currentArg));
        }
        
        return $args;
    }

    /**
     * Process an argument value
     *
     * @param string $arg The argument string
     * @return mixed The processed argument value
     */
    private function processArgumentValue($arg) {
        // Check if it's a quoted string
        if ((substr($arg, 0, 1) === '"' && substr($arg, -1) === '"') || 
            (substr($arg, 0, 1) === "'" && substr($arg, -1) === "'")) {
            return substr($arg, 1, -1);
        }
        
        // Check if it's a number
        if (is_numeric($arg)) {
            return $arg + 0; // Convert to int or float
        }
        
        // Check if it's a boolean
        if ($arg === 'true') return true;
        if ($arg === 'false') return false;
        if ($arg === 'null') return null;
        
        // Check for nested function calls like strtotime(user.details.joined)
        if (preg_match('/^([a-zA-Z0-9_]+)\s*\(\s*(.*?)\s*\)$/', $arg, $matches)) {
            $nestedFunction = $matches[1];
            $nestedArgs = $this->parseFunctionArguments($matches[2]);
            
            // List of allowed functions for security
            $allowedFunctions = [
                'htmlspecialchars', 'htmlentities', 'strip_tags',
                'strtoupper', 'strtolower', 'ucfirst', 'lcfirst', 'ucwords',
                'number_format', 'round', 'floor', 'ceil', 'abs',
                'count', 'sizeof', 'implode', 'explode', 'trim', 'ltrim', 'rtrim',
                'date', 'time', 'strtotime', 'nl2br', 'json_encode', 'md5', 'sha1',
                'isset', 'empty', 'is_array', 'is_string', 'is_numeric', 'is_object'
            ];
            
            // Check if function is allowed
            if (in_array($nestedFunction, $allowedFunctions)) {
                try {
                    return call_user_func_array($nestedFunction, $nestedArgs);
                } catch (Exception $e) {
                    if ($this->debugMode) {
                        error_log("Template Debug: Error in nested function '{$nestedFunction}': " . $e->getMessage());
                    }
                    return null;
                }
            }
        }
        
        // Check if it's a variable path
        if (preg_match('/^[a-zA-Z0-9._\[\]]+$/', $arg)) {
            return $this->getNestedValue($arg);
        }
        
        // Default: return as is
        return $arg;
    }

    /**
     * Get a nested value from the variables array using bracket notation
     *
     * @param string $path The path to the value (e.g. "user[details][balance]")
     * @return mixed The value at the path or null if not found
     */
    private function getBracketValue($path) {
        // Parse the path into parts
        $matches = [];
        preg_match_all('/([a-zA-Z0-9_]+)(?:\[([^\]]+)\])?/', $path, $matches, PREG_SET_ORDER);
        
        if (empty($matches)) {
            return null;
        }
        
        // Start with the root variable
        $rootVar = $matches[0][1];
        if (!isset($this->variables[$rootVar])) {
            return null;
        }
        
        $current = $this->variables[$rootVar];
        
        // Process the first bracket if it exists
        if (isset($matches[0][2])) {
            $key = $matches[0][2];
            // Remove quotes if present
            if ((substr($key, 0, 1) === '"' && substr($key, -1) === '"') || 
                (substr($key, 0, 1) === "'" && substr($key, -1) === "'")) {
                $key = substr($key, 1, -1);
            }
            
            if (is_array($current) && isset($current[$key])) {
                $current = $current[$key];
            } else {
                return null;
            }
        }
        
        // Process remaining parts
        for ($i = 1; $i < count($matches); $i++) {
            $part = $matches[$i];
            
            // Handle dot notation after brackets
            if (isset($part[1])) {
                $key = $part[1];
                
                if (is_array($current) && isset($current[$key])) {
                    $current = $current[$key];
                } else {
                    return null;
                }
            }
            
            // Handle bracket notation
            if (isset($part[2])) {
                $key = $part[2];
                // Remove quotes if present
                if ((substr($key, 0, 1) === '"' && substr($key, -1) === '"') || 
                    (substr($key, 0, 1) === "'" && substr($key, -1) === "'")) {
                    $key = substr($key, 1, -1);
                }
                
                if (is_array($current) && isset($current[$key])) {
                    $current = $current[$key];
                } else {
                    return null;
                }
            }
        }
        
        return $current;
    }

}
?>
