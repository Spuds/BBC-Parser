<?php

// @todo change to \StringParser\BBC
namespace BBC;

use \BBC\Codes;

//define('BR', '<br />');
//define('BR_LEN', strlen(BR));

// Anywhere you see - 1 + 2 it's because you get rid of the ] and add 2 \n

class Parser
{
	protected $message;
	protected $bbc;
	protected $bbc_codes;
	protected $item_codes;
	protected $tags;
	protected $pos;
	protected $pos1;
	protected $pos2;
	protected $pos3;
	protected $last_pos;
	protected $do_smileys = true;
	// This is just the name of the tags that are open, by key
	protected $open_tags = array();
	// This is the actual tag that's open
	// @todo implement as SplStack
	protected $open_bbc = array();
	protected $do_autolink = true;
	protected $inside_tag;
	protected $lastAutoPos;

	private $original_msg;

	public function __construct(Codes $bbc)
	{
		$this->bbc = $bbc;

		$this->bbc_codes = $this->bbc->getForParsing();
		$this->item_codes = $this->bbc->getItemCodes();
		//$this->tags = $this->bbc->getTags();
	}

	public function resetParser()
	{
		//$this->tags = null;
		$this->pos = null;
		$this->pos1 = null;
		$this->pos2 = null;
		$this->last_pos = null;
		$this->open_tags = array();
		//$this->open_bbc = new \SplStack;
		$this->do_autolink = true;
		$this->inside_tag = null;
		$this->lastAutoPos = 0;
	}

	public function parse($message)
	{
		$this->message = $message;

		// Don't waste cycles
		if ($this->message === '')
		{
			return '';
		}

		// Clean up any cut/paste issues we may have
		$this->message = sanitizeMSCutPaste($this->message);

		// Unfortunately, this has to be done here because smileys are parsed as blocks between BBC
		// @todo remove from here and make the caller figure it out
		if (!$this->parsingEnabled())
		{
			if ($this->do_smileys)
			{
				parsesmileys($this->message);
			}

			return $this->message;
		}

		$this->resetParser();

		// Get the BBC
		$bbc_codes = $this->bbc_codes;

		// @todo change this to <br> (it will break tests)
		$this->message = str_replace("\n", '<br />', $this->message);
//$this->tokenize($this->message);

		$this->pos = -1;
		while ($this->pos !== false)
		{
			$this->last_pos = isset($this->last_pos) ? max($this->pos, $this->last_pos) : $this->pos;
			$this->pos = strpos($this->message, '[', $this->pos + 1);

			// Failsafe.
			if ($this->pos === false || $this->last_pos > $this->pos)
			{
				$this->pos = strlen($this->message) + 1;
			}

			// Can't have a one letter smiley, URL, or email! (sorry.)
			if ($this->last_pos < $this->pos - 1)
			{
				$this->betweenTags();
			}

			// Are we there yet?  Are we there yet?
			if ($this->pos >= strlen($this->message) - 1)
			{
				break;
			}

			$tags = strtolower($this->message[$this->pos + 1]);

			// Possibly a closer?
			if ($tags === '/')
			{
				if($this->hasOpenTags())
				{
					// Next closing bracket after the first character
					$this->pos2 = strpos($this->message, ']', $this->pos + 1);

					// Playing games? string = [/]
					if ($this->pos2 === $this->pos + 2)
					{
						continue;
					}

					// Get everything between [/ and ]
					$look_for = strtolower(substr($this->message, $this->pos + 2, $this->pos2 - $this->pos - 2));
					$to_close = array();
					$block_level = null;

					do
					{
						// Get the last opened tag
						$tag = $this->closeOpenedTag(false);

						// No open tags
						if (!$tag)
						{
							break;
						}

						if ($tag[Codes::ATTR_BLOCK_LEVEL])
						{
							// Only find out if we need to.
							if ($block_level === false)
							{
								$this->addOpenTag($tag);
								break;
							}

							// The idea is, if we are LOOKING for a block level tag, we can close them on the way.
							if (isset($look_for[1]) && isset($bbc_codes[$look_for[0]]))
							{
								foreach ($bbc_codes[$look_for[0]] as $temp)
								{
									if ($temp[Codes::ATTR_TAG] === $look_for)
									{
										$block_level = $temp[Codes::ATTR_BLOCK_LEVEL];
										break;
									}
								}
							}

							if ($block_level !== true)
							{
								$block_level = false;
								$this->addOpenTag($tag);
								break;
							}
						}

						$to_close[] = $tag;
					} while ($tag[Codes::ATTR_TAG] !== $look_for);

					// Did we just eat through everything and not find it?
					if (!$this->hasOpenTags() && (empty($tag) || $tag[Codes::ATTR_TAG] !== $look_for))
					{
						$this->open_tags = $to_close;
						continue;
					}
					elseif (!empty($to_close) && $tag[Codes::ATTR_TAG] !== $look_for)
					{
						if ($block_level === null && isset($look_for[0], $bbc_codes[$look_for[0]]))
						{
							foreach ($bbc_codes[$look_for[0]] as $temp)
							{
								if ($temp[Codes::ATTR_TAG] === $look_for)
								{
									$block_level = !empty($temp[Codes::ATTR_BLOCK_LEVEL]);
									break;
								}
							}
						}

						// We're not looking for a block level tag (or maybe even a tag that exists...)
						if (!$block_level)
						{
							foreach ($to_close as $tag)
							{
								$this->addOpenTag($tag);
							}

							continue;
						}
					}

					foreach ($to_close as $tag)
					{
						//$this->message = substr($this->message, 0, $this->pos) . "\n" . $tag[Codes::ATTR_AFTER] . "\n" . substr($this->message, $this->pos2 + 1);
						//$this->message = substr_replace($this->message, "\n" . $tag[Codes::ATTR_AFTER] . "\n", $this->pos, $this->pos2 + 1 - $this->pos);
						$tmp = $this->noSmileys($tag[Codes::ATTR_AFTER]);
						$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos2 + 1 - $this->pos);
						//$this->pos += strlen($tag[Codes::ATTR_AFTER]) + 2;
						$this->pos += strlen($tmp);
						$this->pos2 = $this->pos - 1;

						// See the comment at the end of the big loop - just eating whitespace ;).
						if ($tag[Codes::ATTR_BLOCK_LEVEL] && isset($this->message[$this->pos]) && substr_compare($this->message, '<br />', $this->pos, 6) === 0)
							//if (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) && substr($this->message, $this->pos, 6) === '<br />')
						{
							// $this->message = substr($this->message, 0, $this->pos) . substr($this->message, $this->pos + 6);
							$this->message = substr_replace($this->message, '', $this->pos, 6);
						}

						// Trim inside whitespace
						if (!empty($tag[Codes::ATTR_TRIM]) && $tag[Codes::ATTR_TRIM] !== Codes::TRIM_INSIDE)
						{
							$this->trimWhiteSpace($this->message, $this->pos + 1);
						}
					}

					if (!empty($to_close))
					{
						$to_close = array();
						$this->pos--;
					}
				}

				// We don't allow / to be used for anything but the closing character, so this can't be a tag
				continue;
			}

			// No tags for this character, so just keep going (fastest possible course.)
			if (!isset($bbc_codes[$tags]))
			{
				continue;
			}

			$this->inside_tag = !$this->hasOpenTags() ? null : $this->getLastOpenedTag();
			// @todo figure out if this is an itemcode first
			$tag = $this->isItemCode($tags) ? null : $this->findTag($bbc_codes[$tags]);

			//if (!empty($tag['itemcode'])
			if ($tag === null
				// Why does smilies being on/off affect item codes?
				//	&& $this->do_smileys
				&& isset($this->message[$this->pos + 2])
				&& $this->message[$this->pos + 2] === ']'
				&& $this->isItemCode($this->message[$this->pos + 1])
				&& !$this->bbc->isDisabled('list')
				&& !$this->bbc->isDisabled('li')
			)
			{
				// Itemcodes cannot be 0 and must be preceeded by a semi-colon, space, tab, new line, or greater than sign
				if (!($this->message[$this->pos + 1] === '0' && !in_array($this->message[$this->pos - 1], array(';', ' ', "\t", "\n", '>'))))
				{
					// Item codes are complicated buggers... they are implicit [li]s and can make [list]s!
					$this->handleItemCode();
				}

				continue;
			}

			// Implicitly close lists and tables if something other than what's required is in them. This is needed for itemcode.
			if ($tag === null && $this->inside_tag !== null && !empty($this->inside_tag[Codes::ATTR_REQUIRE_CHILDREN]))
			{
				$this->closeOpenedTag();
				//$this->message = substr_replace($this->message, "\n" . $this->inside_tag[Codes::ATTR_AFTER] . "\n", $this->pos, 0);
				$tmp = $this->noSmileys($this->inside_tag[Codes::ATTR_AFTER]);
				$this->message = substr_replace($this->message, $tmp, $this->pos, 0);
				//$this->pos += strlen($this->inside_tag[Codes::ATTR_AFTER]) - 1 + 2;
				$this->pos += strlen($tmp) - 1;
			}

			// No tag?  Keep looking, then.  Silly people using brackets without actual tags.
			if ($tag === null)
			{
				continue;
			}

			// Propagate the list to the child (so wrapping the disallowed tag won't work either.)
			if (isset($this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN]))
			{
				//$tag[Codes::ATTR_DISALLOW_CHILDREN] = isset($tag[Codes::ATTR_DISALLOW_CHILDREN]) ? array_unique(array_merge($tag[Codes::ATTR_DISALLOW_CHILDREN], $this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN])) : $this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN];
				$tag[Codes::ATTR_DISALLOW_CHILDREN] = isset($tag[Codes::ATTR_DISALLOW_CHILDREN]) ? $tag[Codes::ATTR_DISALLOW_CHILDREN] + $this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN] : $this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN];
			}

			// Is this tag disabled?
			if ($this->bbc->isDisabled($tag[Codes::ATTR_TAG]))
			{
				$this->handleDisabled($tag);
			}

			// The only special case is 'html', which doesn't need to close things.
			if ($tag[Codes::ATTR_BLOCK_LEVEL] && $tag[Codes::ATTR_TAG] !== 'html' && !$this->inside_tag[Codes::ATTR_BLOCK_LEVEL])
			{
				$this->closeNonBlockLevel();
			}

			// This is the part where we actually handle the tags. I know, crazy how long it took.
			if($this->handleTag($tag))
			{
				continue;
			}

			// If this is block level, eat any breaks after it.
			if ($tag[Codes::ATTR_BLOCK_LEVEL] && isset($this->message[$this->pos + 1]) && substr_compare($this->message, '<br />', $this->pos + 1, 6) === 0)
				//if (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) && substr($this->message, $this->pos + 1, 6) === '<br />')
			{
				$this->message = substr_replace($this->message, '', $this->pos + 1, 6);
				//$this->message = substr($this->message, 0, $this->pos + 1) . substr($this->message, $this->pos + 7);
			}

			// Are we trimming outside this tag?
			if (!empty($tag[Codes::ATTR_TRIM]) && $tag[Codes::ATTR_TRIM] !== Codes::TRIM_OUTSIDE)
			{
				$this->trimWhiteSpace($this->message, $this->pos + 1);
			}
		}

		// Close any remaining tags.
		while ($tag = $this->closeOpenedTag())
		{
			//$this->message .= "\n" . $tag[Codes::ATTR_AFTER] . "\n";
			$this->message .= $this->noSmileys($tag[Codes::ATTR_AFTER]);
		}

		// Parse the smileys within the parts where it can be done safely.
		if ($this->do_smileys === true)
		{
			$message_parts = explode("\n", $this->message);

			for ($i = 0, $n = count($message_parts); $i < $n; $i += 2)
			{
				parsesmileys($message_parts[$i]);
				//parsesmileys($this->message);
			}

			$this->message = implode('', $message_parts);
		}
		// No smileys, just get rid of the markers.
		else
		{
			$this->message = str_replace("\n", '', $this->message);
		}

		if (isset($this->message[0]) && $this->message[0] === ' ')
		{
			$this->message = '&nbsp;' . substr($this->message, 1);
		}

		// Cleanup whitespace.
		// @todo remove \n because it should never happen after the explode/str_replace. Replace with str_replace
		$this->message = strtr($this->message, array('  ' => '&nbsp; ', "\r" => '', "\n" => '<br />', '<br /> ' => '<br />&nbsp;', '&#13;' => "\n"));

		// Finish footnotes if we have any.
		if (strpos($this->message, '<sup class="bbc_footnotes">') !== false)
		{
			$this->handleFootnotes();
		}

		// Allow addons access to what the parser created
		$message = $this->message;
		call_integration_hook('integrate_post_parsebbc', array(&$message));
		$this->message = $message;

		return $this->message;
	}

	/**
	 * Turn smiley parsing on/off
	 * @param bool $toggle
	 * @return \BBC\Parser
	 */
	public function doSmileys($toggle)
	{
		$this->do_smileys = (bool) $toggle;
		return $this;
	}

	public function parsingEnabled()
	{
		return !empty($GLOBALS['modSettings']['enableBBC']);
	}

	protected function parseHTML(&$data)
	{
		global $modSettings;

		//$data = preg_replace('~&lt;a\s+href=((?:&quot;)?)((?:https?://|ftps?://|mailto:)\S+?)\\1&gt;~i', '[url=$2]', $data);
		$data = preg_replace('~&lt;a\s+href=((?:&quot;)?)((?:https?://|mailto:)\S+?)\\1&gt;~i', '[url=$2]', $data);
		$data = preg_replace('~&lt;/a&gt;~i', '[/url]', $data);

		// <br /> should be empty.
		$empty_tags = array('br', 'hr');
		foreach ($empty_tags as $tag)
		{
			$data = str_replace(array('&lt;' . $tag . '&gt;', '&lt;' . $tag . '/&gt;', '&lt;' . $tag . ' /&gt;'), '[' . $tag . ' /]', $data);
		}

		// b, u, i, s, pre... basic tags.
		$closable_tags = array('b', 'u', 'i', 's', 'em', 'ins', 'del', 'pre', 'blockquote');
		foreach ($closable_tags as $tag)
		{
			$diff = substr_count($data, '&lt;' . $tag . '&gt;') - substr_count($data, '&lt;/' . $tag . '&gt;');
			$data = strtr($data, array('&lt;' . $tag . '&gt;' => '<' . $tag . '>', '&lt;/' . $tag . '&gt;' => '</' . $tag . '>'));

			if ($diff > 0)
			{
				$data = substr($data, 0, -1) . str_repeat('</' . $tag . '>', $diff) . substr($data, -1);
			}
		}

		// Do <img ... /> - with security... action= -> action-.
		//preg_match_all('~&lt;img\s+src=((?:&quot;)?)((?:https?://|ftps?://)\S+?)\\1(?:\s+alt=(&quot;.*?&quot;|\S*?))?(?:\s?/)?&gt;~i', $data, $matches, PREG_PATTERN_ORDER);
		preg_match_all('~&lt;img\s+src=((?:&quot;)?)((?:https?://)\S+?)\\1(?:\s+alt=(&quot;.*?&quot;|\S*?))?(?:\s?/)?&gt;~i', $data, $matches, PREG_PATTERN_ORDER);
		if (!empty($matches[0]))
		{
			$replaces = array();
			foreach ($matches[2] as $match => $imgtag)
			{
				$alt = empty($matches[3][$match]) ? '' : ' alt=' . preg_replace('~^&quot;|&quot;$~', '', $matches[3][$match]);

				// Remove action= from the URL - no funny business, now.
				if (preg_match('~action(=|%3d)(?!dlattach)~i', $imgtag) !== 0)
				{
					$imgtag = preg_replace('~action(?:=|%3d)(?!dlattach)~i', 'action-', $imgtag);
				}

				// Check if the image is larger than allowed.
				// @todo - We should seriously look at deprecating some of $this in favour of CSS resizing.
				if (!empty($modSettings['max_image_width']) && !empty($modSettings['max_image_height']))
				{
					// For images, we'll want $this.
					require_once(SUBSDIR . '/Attachments.subs.php');
					list ($width, $height) = url_image_size($imgtag);

					if (!empty($modSettings['max_image_width']) && $width > $modSettings['max_image_width'])
					{
						$height = (int) (($modSettings['max_image_width'] * $height) / $width);
						$width = $modSettings['max_image_width'];
					}

					if (!empty($modSettings['max_image_height']) && $height > $modSettings['max_image_height'])
					{
						$width = (int) (($modSettings['max_image_height'] * $width) / $height);
						$height = $modSettings['max_image_height'];
					}

					// Set the new image tag.
					$replaces[$matches[0][$match]] = '[img width=' . $width . ' height=' . $height . $alt . ']' . $imgtag . '[/img]';
				}
				else
					$replaces[$matches[0][$match]] = '[img' . $alt . ']' . $imgtag . '[/img]';
			}

			$data = strtr($data, $replaces);
		}
	}

	protected function autoLink(&$data)
	{
		static $search, $replacements;

		// Are we inside tags that should be auto linked?
		$autolink_area = true;
		if ($this->hasOpenTags())
		{
			foreach ($this->open_tags as $open_tag)
			{
				if (!$open_tag[Codes::ATTR_AUTOLINK])
				{
					$autolink_area = false;
				}
			}
		}

		// Don't go backwards.
		// @todo Don't think is the real solution....
		$this->lastAutoPos = isset($this->lastAutoPos) ? $this->lastAutoPos : 0;
		if ($this->pos < $this->lastAutoPos)
		{
			$autolink_area = false;
		}
		$this->lastAutoPos = $this->pos;

		if ($autolink_area)
		{
			// Parse any URLs.... have to get rid of the @ problems some things cause... stupid email addresses.
			if (!$this->bbc->isDisabled('url') && (strpos($data, '://') !== false || strpos($data, 'www.') !== false) && strpos($data, '[url') === false)
			{
				// Switch out quotes really quick because they can cause problems.
				$data = strtr($data, array('&#039;' => '\'', '&nbsp;' => "\xC2\xA0", '&quot;' => '>">', '"' => '<"<', '&lt;' => '<lt<'));

				if ($search === null)
				{
					// @todo get rid of the FTP, nobody uses it
					$search = array(
						'~(?<=[\s>\.(;\'"]|^)((?:http|https)://[\w\-_%@:|]+(?:\.[\w\-_%]+)*(?::\d+)?(?:/[\p{L}\p{N}\-_\~%\.@!,\?&;=#(){}+:\'\\\\]*)*[/\p{L}\p{N}\-_\~%@\?;=#}\\\\])~ui',
						//'~(?<=[\s>\.(;\'"]|^)((?:ftp|ftps)://[\w\-_%@:|]+(?:\.[\w\-_%]+)*(?::\d+)?(?:/[\w\-_\~%\.@,\?&;=#(){}+:\'\\\\]*)*[/\w\-_\~%@\?;=#}\\\\])~i',
						'~(?<=[\s>(\'<]|^)(www(?:\.[\w\-_]+)+(?::\d+)?(?:/[\p{L}\p{N}\-_\~%\.@!,\?&;=#(){}+:\'\\\\]*)*[/\p{L}\p{N}\-_\~%@\?;=#}\\\\])~ui'
					);
					$replacements = array(
						'[url]$1[/url]',
						//'[ftp]$1[/ftp]',
						'[url=http://$1]$1[/url]'
					);

					call_integration_hook('integrate_autolink', array(&$search, &$replacements, $this->bbc));
				}

				$result = preg_replace($search, $replacements, $data);

				// Only do this if the preg survives.
				if (is_string($result))
				{
					$data = $result;
				}

				// Switch those quotes back
				$data = strtr($data, array('\'' => '&#039;', "\xC2\xA0" => '&nbsp;', '>">' => '&quot;', '<"<' => '"', '<lt<' => '&lt;'));
			}

			// Next, emails...
			if (!$this->bbc->isDisabled('email') && strpos($data, '@') !== false && strpos($data, '[email') === false)
			{
				$data = preg_replace('~(?<=[\?\s\x{A0}\[\]()*\\\;>]|^)([\w\-\.]{1,80}@[\w\-]+\.[\w\-\.]+[\w\-])(?=[?,\s\x{A0}\[\]()*\\\]|$|<br />|&nbsp;|&gt;|&lt;|&quot;|&#039;|\.(?:\.|;|&nbsp;|\s|$|<br />))~u', '[email]$1[/email]', $data);
				$data = preg_replace('~(?<=<br />)([\w\-\.]{1,80}@[\w\-]+\.[\w\-\.]+[\w\-])(?=[?\.,;\s\x{A0}\[\]()*\\\]|$|<br />|&nbsp;|&gt;|&lt;|&quot;|&#039;)~u', '[email]$1[/email]', $data);
			}
		}
	}

	protected function findTag(array $possible_codes)
	{
		$tag = null;
		$last_check = null;

		foreach ($possible_codes as $possible)
		{
			// Skip tags that didn't match the next X characters
			if ($possible[Codes::ATTR_TAG] === $last_check)
			{
				continue;
			}

			// Not a match?
			if (substr_compare($this->message, $possible[Codes::ATTR_TAG], $this->pos + 1, $possible[Codes::ATTR_LENGTH], true) !== 0)
			{
				$last_check = $possible[Codes::ATTR_TAG];

				continue;
			}

			// The character after the possible tag or nothing
			// @todo shouldn't this return if empty since there needs to be a ]?
			$next_c = isset($this->message[$this->pos + 1 + $possible[Codes::ATTR_LENGTH]]) ? $this->message[$this->pos + 1 + $possible[Codes::ATTR_LENGTH]] : '';

			// A test validation?
			// @todo figure out if the regex need can use offset
			// this creates a copy of the entire message starting from this point!
			// @todo where do we know if the next char is ]?
			//if (isset($possible[Codes::ATTR_TEST]) && preg_match('~^' . $possible[Codes::ATTR_TEST] . '~', substr($this->message, $this->pos + 1 + $possible[Codes::ATTR_LENGTH] + 1)) === 0)
			if (isset($possible[Codes::ATTR_TEST]) && preg_match('~^' . $possible[Codes::ATTR_TEST] . '~', substr($this->message, $this->pos + 2 + $possible[Codes::ATTR_LENGTH], strpos($this->message, ']', $this->pos) - ($this->pos + 2 + $possible[Codes::ATTR_LENGTH]))) === 0)
			{
				continue;
			}
			// Do we want parameters?
			elseif (!empty($possible[Codes::ATTR_PARAM]))
			{
				if ($next_c !== ' ')
				{
					continue;
				}
			}
			elseif ($possible[Codes::ATTR_TYPE] !== Codes::TYPE_PARSED_CONTENT)
			{
				// Do we need an equal sign?
				if ($next_c !== '=' && in_array($possible[Codes::ATTR_TYPE], array(Codes::TYPE_UNPARSED_EQUALS, Codes::TYPE_UNPARSED_COMMAS, Codes::TYPE_UNPARSED_COMMAS_CONTENT, Codes::TYPE_UNPARSED_EQUALS_CONTENT, Codes::TYPE_PARSED_EQUALS)))
				{
					continue;
				}

				if ($next_c !== ']')
				{
					// An immediate ]?
					if ($possible[Codes::ATTR_TYPE] === Codes::TYPE_UNPARSED_CONTENT)
					{
						continue;
					}
					// Maybe we just want a /...
					elseif ($possible[Codes::ATTR_TYPE] === Codes::TYPE_CLOSED && substr_compare($this->message, '/]', $this->pos + 1 + $possible[Codes::ATTR_LENGTH], 2) !== 0 && substr_compare($this->message, ' /]', $this->pos + 1 + $possible[Codes::ATTR_LENGTH], 3) !== 0)
					{
						continue;
					}
				}
			}
			// parsed_content demands an immediate ] without parameters!
			elseif ($possible[Codes::ATTR_TYPE] === Codes::TYPE_PARSED_CONTENT)
			{
				if ($next_c !== ']')
				{
					continue;
				}
			}

			// Check allowed tree?
			if (isset($possible[Codes::ATTR_REQUIRE_PARENTS]) && ($this->inside_tag === null || !in_array($this->inside_tag[Codes::ATTR_TAG], $possible[Codes::ATTR_REQUIRE_PARENTS])))
			{
				continue;
			}

			if (isset($this->inside_tag[Codes::ATTR_REQUIRE_CHILDREN]) && !in_array($possible[Codes::ATTR_TAG], $this->inside_tag[Codes::ATTR_REQUIRE_CHILDREN]))
			{
				continue;
			}
			// If this is in the list of disallowed child tags, don't parse it.
			//elseif (isset($this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN]) && in_array($possible[Codes::ATTR_TAG], $this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN]))
			if (isset($this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN]) && isset($this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN][$possible[Codes::ATTR_TAG]]))
			{
				continue;
			}

			// Not allowed in this parent, replace the tags or show it like regular text
			if (isset($possible[Codes::ATTR_DISALLOW_PARENTS]) && ($this->inside_tag !== null && in_array($this->inside_tag[Codes::ATTR_TAG], $possible[Codes::ATTR_DISALLOW_PARENTS])))
			{
				if (!isset($possible[Codes::ATTR_DISALLOW_BEFORE], $possible[Codes::ATTR_DISALLOW_AFTER]))
				{
					continue;
				}

				$possible[Codes::ATTR_BEFORE] = isset($possible[Codes::ATTR_DISALLOW_BEFORE]) ? $tag[Codes::ATTR_DISALLOW_BEFORE] : $possible[Codes::ATTR_BEFORE];
				$possible[Codes::ATTR_AFTER] = isset($possible[Codes::ATTR_DISALLOW_AFTER]) ? $tag[Codes::ATTR_DISALLOW_AFTER] : $possible[Codes::ATTR_AFTER];
			}

			$this->pos1 = $this->pos + 1 + $possible[Codes::ATTR_LENGTH] + 1;

			// This is long, but it makes things much easier and cleaner.
			if (!empty($possible[Codes::ATTR_PARAM]))
			{
				$match = $this->matchParameters($possible, $matches);

				// Didn't match our parameter list, try the next possible.
				if (!$match)
				{
					continue;
				}

				$tag = $this->setupTagParameters($possible, $matches);
			}
			else
			{
				$tag = $possible;
			}

			// Quotes can have alternate styling, we do this php-side due to all the permutations of quotes.
			if ($tag[Codes::ATTR_TAG] === 'quote')
			{
				// Start with standard
				$quote_alt = false;
				foreach ($this->open_tags as $open_quote)
				{
					// Every parent quote this quote has flips the styling
					if ($open_quote[Codes::ATTR_TAG] === 'quote')
					{
						$quote_alt = !$quote_alt;
					}
				}
				// Add a class to the quote to style alternating blockquotes
				// @todo - Frankly it makes little sense to allow alternate blockquote
				// styling without also catering for alternate quoteheader styling.
				// I do remember coding that some time back, but it seems to have gotten
				// lost somewhere in the Elk processes.
				// Come to think of it, it may be better to append a second class rather
				// than alter the standard one.
				//  - Example: class="bbc_quote" and class="bbc_quote alt_quote".
				// This would mean simpler CSS for themes (like default) which do not use the alternate styling,
				// but would still allow it for themes that want it.
				$tag[Codes::ATTR_BEFORE] = str_replace('<blockquote>', '<blockquote class="bbc_' . ($quote_alt ? 'alternate' : 'standard') . '_quote">', $tag[Codes::ATTR_BEFORE]);
			}

			break;
		}

		return $tag;
	}

	protected function handleItemCode()
	{
		$tag = $this->item_codes[$this->message[$this->pos + 1]];

		// First let's set up the tree: it needs to be in a list, or after an li.
		if ($this->inside_tag === null || ($this->inside_tag[Codes::ATTR_TAG] !== 'list' && $this->inside_tag[Codes::ATTR_TAG] !== 'li'))
		{
			$this->addOpenTag(array(
				Codes::ATTR_TAG => 'list',
				Codes::ATTR_TYPE => Codes::TYPE_PARSED_CONTENT,
				Codes::ATTR_AFTER => '</ul>',
				Codes::ATTR_BLOCK_LEVEL => true,
				Codes::ATTR_REQUIRE_CHILDREN => array('li'),
				Codes::ATTR_DISALLOW_CHILDREN => isset($this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN]) ? $this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN] : null,
				Codes::ATTR_LENGTH => 4,
				Codes::ATTR_AUTOLINK => true,
			));
			$code = '<ul' . ($tag === '' ? '' : ' style="list-style-type: ' . $tag . '"') . ' class="bbc_list">';
		}
		// We're in a list item already: another itemcode?  Close it first.
		elseif ($this->inside_tag[Codes::ATTR_TAG] === 'li')
		{
			$this->closeOpenedTag();
			$code = '</li>';
		}
		else
		{
			$code = '';
		}

		// Now we open a new tag.
		$this->addOpenTag(array(
			Codes::ATTR_TAG => 'li',
			Codes::ATTR_TYPE => Codes::TYPE_PARSED_CONTENT,
			Codes::ATTR_AFTER => '</li>',
			Codes::ATTR_TRIM => Codes::TRIM_OUTSIDE,
			Codes::ATTR_BLOCK_LEVEL => true,
			Codes::ATTR_DISALLOW_CHILDREN => isset($this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN]) ? $this->inside_tag[Codes::ATTR_DISALLOW_CHILDREN] : null,
			Codes::ATTR_AUTOLINK => true,
			Codes::ATTR_LENGTH => 2,
		));

		// First, open the tag...
		$code .= '<li>';

		//$this->message = substr($this->message, 0, $this->pos) . "\n" . $code . "\n" . substr($this->message, $this->pos + 3);
		//$this->message = substr_replace($this->message, "\n" . $code . "\n", $this->pos, 3);
		$tmp = $this->noSmileys($code);
		$this->message = substr_replace($this->message, $tmp, $this->pos, 3);
		//$this->pos += strlen($code) + 1;
		$this->pos += strlen($tmp) - 1;

		// Next, find the next break (if any.)  If there's more itemcode after it, keep it going - otherwise close!
		$this->pos2 = strpos($this->message, '<br />', $this->pos);
		$this->pos3 = strpos($this->message, '[/', $this->pos);

		$num_open_tags = count($this->open_tags);
		if ($this->pos2 !== false && ($this->pos3 === false || $this->pos2 <= $this->pos3))
		{
			// Can't use offset because of the ^
			preg_match('~^(<br />|&nbsp;|\s|\[)+~', substr($this->message, $this->pos2 + 6), $matches);

			// Keep the list open if the next character after the break is a [. Otherwise, close it.
			$replacement = (!empty($matches[0]) && substr_compare($matches[0], '[', -1, 1) === 0 ? '[/li]' : '[/li][/list]');
			//$this->message = substr($this->message, 0, $this->pos2)	. $replacement . substr($this->message, $this->pos2);
			$this->message = substr_replace($this->message, $replacement, $this->pos2, 0);
			$this->open_tags[$num_open_tags - 2][Codes::ATTR_AFTER] = '</ul>';
		}
		// Tell the [list] that it needs to close specially.
		else
		{
			// Move the li over, because we're not sure what we'll hit.
			$this->open_tags[$num_open_tags - 1][Codes::ATTR_AFTER] = '';
			$this->open_tags[$num_open_tags - 2][Codes::ATTR_AFTER] = '</li></ul>';
		}
	}

	protected function handleTag($tag)
	{
		switch ($tag[Codes::ATTR_TYPE])
		{
			case Codes::TYPE_PARSED_CONTENT:
				// @todo Check for end tag first, so people can say "I like that [i] tag"?
				$this->addOpenTag($tag);
				//$this->message = substr($this->message, 0, $this->pos) . "\n" . $tag[Codes::ATTR_BEFORE] . "\n" . substr($this->message, $this->pos1);
				//$this->message = substr_replace($this->message, "\n" . $tag[Codes::ATTR_BEFORE] . "\n", $this->pos, $this->pos1 - $this->pos);
				$tmp = $this->noSmileys($tag[Codes::ATTR_BEFORE]);
				$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos1 - $this->pos);
				//$this->pos += strlen($tag[Codes::ATTR_BEFORE]) + 1;
				$this->pos += strlen($tmp) - 1;
				break;

			// Don't parse the content, just skip it.
			case Codes::TYPE_UNPARSED_CONTENT:
				// Find the next closer
				$this->pos2 = stripos($this->message, '[/' . $tag[Codes::ATTR_TAG] . ']', $this->pos1);

				// No closer
				if ($this->pos2 === false)
				{
					return true;
				}

				// @todo figure out how to make this move to the validate part
				$data = substr($this->message, $this->pos1, $this->pos2 - $this->pos1);

				//if (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) && substr_compare($this->message, '<br />', $this->pos, 6) === 0)
				//if (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) && substr($data, 0, 6) === '<br />')
				if (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) && isset($data[0]) && substr_compare($data, '<br />', 0, 6) === 0)
				{
					$data = substr($data, 6);
					//$this->message = substr_replace($this->message, '', $this->pos, 6);
				}

				if (isset($tag[Codes::ATTR_VALIDATE]))
				{
					$tag[Codes::ATTR_VALIDATE]($tag, $data, $this->bbc->getDisabled());
				}

				$code = strtr($tag[Codes::ATTR_CONTENT], array('$1' => $data));
				//$this->message = substr($this->message, 0, $this->pos) . "\n" . $code . "\n" . substr($this->message, $this->pos2 + 3 + $tag[Codes::ATTR_LENGTH]);
				//$this->message = substr_replace($this->message, "\n" . $code . "\n", $this->pos, $this->pos2 + 3 + $tag[Codes::ATTR_LENGTH] - $this->pos);
				$tmp = $this->noSmileys($code);
				$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos2 + 3 + $tag[Codes::ATTR_LENGTH] - $this->pos);

				//$this->pos += strlen($code) - 1 + 2;
				$this->pos += strlen($tmp) - 1;
				$this->last_pos = $this->pos + 1;
				break;

			// Don't parse the content, just skip it.
			case Codes::TYPE_UNPARSED_EQUALS_CONTENT:
				// The value may be quoted for some tags - check.
				if (isset($tag[Codes::ATTR_QUOTED]))
				{
					$quoted = substr_compare($this->message, '&quot;', $this->pos1, 6) === 0;
					if ($tag[Codes::ATTR_QUOTED] !== Codes::OPTIONAL && !$quoted)
					{
						return true;
					}

					if ($quoted)
					{
						$this->pos1 += 6;
					}
				}
				else
					$quoted = false;

				$this->pos2 = strpos($this->message, $quoted === false ? ']' : '&quot;]', $this->pos1);
				if ($this->pos2 === false)
				{
					return true;
				}

				$this->pos3 = stripos($this->message, '[/' . $tag[Codes::ATTR_TAG] . ']', $this->pos2);
				if ($this->pos3 === false)
				{
					return true;
				}

				$data = array(
					substr($this->message, $this->pos2 + ($quoted === false ? 1 : 7), $this->pos3 - ($this->pos2 + ($quoted === false ? 1 : 7))),
					substr($this->message, $this->pos1, $this->pos2 - $this->pos1)
				);

				if (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) && substr_compare($data[0], '<br />', 0, 6) === 0)
				{
					$data[0] = substr($data[0], 6);
				}

				// Validation for my parking, please!
				if (isset($tag[Codes::ATTR_VALIDATE]))
				{
					$tag[Codes::ATTR_VALIDATE]($tag, $data, $this->bbc->getDisabled());
				}

				$code = strtr($tag[Codes::ATTR_CONTENT], array('$1' => $data[0], '$2' => $data[1]));
				//$this->message = substr($this->message, 0, $this->pos) . "\n" . $code . "\n" . substr($this->message, $this->pos3 + 3 + $tag[Codes::ATTR_LENGTH]);
				//$this->message = substr_replace($this->message, "\n" . $code . "\n", $this->pos, $this->pos3 + 3 + $tag[Codes::ATTR_LENGTH] - $this->pos);
				$tmp = $this->noSmileys($code);
				$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos3 + 3 + $tag[Codes::ATTR_LENGTH] - $this->pos);
				//$this->pos += strlen($code) - 1 + 2;
				$this->pos += strlen($tmp) - 1;
				break;

			// A closed tag, with no content or value.
			case Codes::TYPE_CLOSED:
				$this->pos2 = strpos($this->message, ']', $this->pos);
				//$this->message = substr($this->message, 0, $this->pos) . "\n" . $tag[Codes::ATTR_CONTENT] . "\n" . substr($this->message, $this->pos2 + 1);
				//$this->message = substr_replace($this->message, "\n" . $tag[Codes::ATTR_CONTENT] . "\n", $this->pos, $this->pos2 + 1 - $this->pos);
				$tmp = $this->noSmileys($tag[Codes::ATTR_CONTENT]);
				$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos2 + 1 - $this->pos);
				//$this->pos += strlen($tag[Codes::ATTR_CONTENT]) - 1 + 2;
				$this->pos += strlen($tmp) - 1;
				break;

			// This one is sorta ugly... :/
			case Codes::TYPE_UNPARSED_COMMAS_CONTENT:
				$this->pos2 = strpos($this->message, ']', $this->pos1);
				if ($this->pos2 === false)
				{
					return true;
				}

				$this->pos3 = stripos($this->message, '[/' . $tag[Codes::ATTR_TAG] . ']', $this->pos2);
				if ($this->pos3 === false)
				{
					return true;
				}

				// We want $1 to be the content, and the rest to be csv.
				$data = explode(',', ',' . substr($this->message, $this->pos1, $this->pos2 - $this->pos1));
				$data[0] = substr($this->message, $this->pos2 + 1, $this->pos3 - $this->pos2 - 1);

				if (isset($tag[Codes::ATTR_VALIDATE]))
				{
					$tag[Codes::ATTR_VALIDATE]($tag, $data, $this->bbc->getDisabled());
				}

				$code = $tag[Codes::ATTR_CONTENT];
				foreach ($data as $k => $d)
				{
					$code = strtr($code, array('$' . ($k + 1) => trim($d)));
				}

				//$this->message = substr($this->message, 0, $this->pos) . "\n" . $code . "\n" . substr($this->message, $this->pos3 + 3 + $tag[Codes::ATTR_LENGTH]);
				//$this->message = substr_replace($this->message, "\n" . $code . "\n", $this->pos, $this->pos3 + 3 + $tag[Codes::ATTR_LENGTH] - $this->pos);
				$tmp = $this->noSmileys($code);
				$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos3 + 3 + $tag[Codes::ATTR_LENGTH] - $this->pos);
				//$this->pos += strlen($code) - 1 + 2;
				$this->pos += strlen($tmp) - 1;
				break;

			// This has parsed content, and a csv value which is unparsed.
			case Codes::TYPE_UNPARSED_COMMAS:
				$this->pos2 = strpos($this->message, ']', $this->pos1);
				if ($this->pos2 === false)
				{
					return true;
				}

				$data = explode(',', substr($this->message, $this->pos1, $this->pos2 - $this->pos1));

				if (isset($tag[Codes::ATTR_VALIDATE]))
				{
					$tag[Codes::ATTR_VALIDATE]($tag, $data, $this->bbc->getDisabled());
				}

				// Fix after, for disabled code mainly.
				foreach ($data as $k => $d)
				{
					$tag[Codes::ATTR_AFTER] = strtr($tag[Codes::ATTR_AFTER], array('$' . ($k + 1) => trim($d)));
				}

				$this->addOpenTag($tag);

				// Replace them out, $1, $2, $3, $4, etc.
				$code = $tag[Codes::ATTR_BEFORE];
				foreach ($data as $k => $d)
				{
					$code = strtr($code, array('$' . ($k + 1) => trim($d)));
				}
				//$this->message = substr($this->message, 0, $this->pos) . "\n" . $code . "\n" . substr($this->message, $this->pos2 + 1);
				//$this->message = substr_replace($this->message, "\n" . $code . "\n", $this->pos, $this->pos2 + 1 - $this->pos);
				$tmp = $this->noSmileys($code);
				$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos2 + 1 - $this->pos);
				//$this->pos += strlen($code) - 1 + 2;
				$this->pos += strlen($tmp) - 1;
				break;

			// A tag set to a value, parsed or not.
			case Codes::TYPE_PARSED_EQUALS:
			case Codes::TYPE_UNPARSED_EQUALS:
				// The value may be quoted for some tags - check.
				if (isset($tag[Codes::ATTR_QUOTED]))
				{
					//$quoted = substr($this->message, $this->pos1, 6) === '&quot;';
					$quoted = substr_compare($this->message, '&quot;', $this->pos1, 6) === 0;
					if ($tag[Codes::ATTR_QUOTED] !== Codes::OPTIONAL && !$quoted)
					{
						return true;
					}

					if ($quoted)
					{
						$this->pos1 += 6;
					}
				}
				else
				{
					$quoted = false;
				}

				$this->pos2 = strpos($this->message, $quoted === false ? ']' : '&quot;]', $this->pos1);
				if ($this->pos2 === false)
				{
					return true;
				}

				$data = substr($this->message, $this->pos1, $this->pos2 - $this->pos1);

				// Validation for my parking, please!
				if (isset($tag[Codes::ATTR_VALIDATE]))
				{
					$tag[Codes::ATTR_VALIDATE]($tag, $data, $this->bbc->getDisabled());
				}

				// For parsed content, we must recurse to avoid security problems.
				if ($tag[Codes::ATTR_TYPE] !== Codes::TYPE_UNPARSED_EQUALS)
				{
//var_dump($this->message, $tag, $data);
					$this->recursiveParser($data, $tag);
				}

				$tag[Codes::ATTR_AFTER] = strtr($tag[Codes::ATTR_AFTER], array('$1' => $data));

				$this->addOpenTag($tag);

				$code = strtr($tag[Codes::ATTR_BEFORE], array('$1' => $data));
				//$this->message = substr($this->message, 0, $this->pos) . "\n" . $code . "\n" . substr($this->message, $this->pos2 + ($quoted === false ? 1 : 7));
				//$this->message = substr_replace($this->message, "\n" . $code . "\n", $this->pos, $this->pos2 + ($quoted === false ? 1 : 7) - $this->pos);
				$tmp = $this->noSmileys($code);
				$this->message = substr_replace($this->message, $tmp, $this->pos, $this->pos2 + ($quoted === false ? 1 : 7) - $this->pos);
				//$this->pos += strlen($code) - 1 + 2;
				$this->pos += strlen($tmp) - 1;
				break;
		}

		return false;
	}

	// @todo I don't know what else to call this. It's the area that isn't a tag.
	protected function betweenTags()
	{
		// Make sure the $this->last_pos is not negative.
		$this->last_pos = max($this->last_pos, 0);

		// Pick a block of data to do some raw fixing on.
		$data = substr($this->message, $this->last_pos, $this->pos - $this->last_pos);

		// Take care of some HTML!
		if (!empty($GLOBALS['modSettings']['enablePostHTML']) && strpos($data, '&lt;') !== false)
		{
			// @todo new \Parser\BBC\HTML;
			$this->parseHTML($data);
		}

		// @todo is this sending tags like [/b] here?
		if (!empty($GLOBALS['modSettings']['autoLinkUrls']))
		{
			$this->autoLink($data);
		}

		// @todo can this be moved much earlier?
		$data = str_replace("\t", '&nbsp;&nbsp;&nbsp;', $data);

		// If it wasn't changed, no copying or other boring stuff has to happen!
		//if ($data !== substr($this->message, $this->last_pos, $this->pos - $this->last_pos))
		if (substr_compare($this->message, $data, $this->last_pos, $this->pos - $this->last_pos))
		{
			//$this->message = substr($this->message, 0, $this->last_pos) . $data . substr($this->message, $this->pos);
			$this->message = substr_replace($this->message, $data, $this->last_pos, $this->pos - $this->last_pos);

			// Since we changed it, look again in case we added or removed a tag.  But we don't want to skip any.
			$old_pos = strlen($data) + $this->last_pos;
			$this->pos = strpos($this->message, '[', $this->last_pos);
			$this->pos = $this->pos === false ? $old_pos : min($this->pos, $old_pos);
		}
	}

	protected function handleFootnotes()
	{
		global $fn_num, $fn_content, $fn_count;
		static $fn_total;

		// @todo temporary until we have nesting
		$this->message = str_replace(array('[footnote]', '[/footnote]'), '', $this->message);

		$fn_num = 0;
		$fn_content = array();
		$fn_count = isset($fn_total) ? $fn_total : 0;

		// Replace our footnote text with a [1] link, save the text for use at the end of the message
		$this->message = preg_replace_callback('~(%fn%(.*?)%fn%)~is', 'footnote_callback', $this->message);
		$fn_total += $fn_num;

		// If we have footnotes, add them in at the end of the message
		if (!empty($fn_num))
		{
			$this->message .= '<div class="bbc_footnotes">' . implode('', $fn_content) . '</div>';
		}
	}

	protected function handleDisabled(&$tag)
	{
		if (!isset($tag[Codes::ATTR_DISABLED_BEFORE]) && !isset($tag[Codes::ATTR_DISABLED_AFTER]) && !isset($tag[Codes::ATTR_DISABLED_CONTENT]))
		{
			$tag[Codes::ATTR_BEFORE] = !empty($tag[Codes::ATTR_BLOCK_LEVEL]) ? '<div>' : '';
			$tag[Codes::ATTR_AFTER] = !empty($tag[Codes::ATTR_BLOCK_LEVEL]) ? '</div>' : '';
			$tag[Codes::ATTR_CONTENT] = $tag[Codes::ATTR_TYPE] === Codes::TYPE_CLOSED ? '' : (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) ? '<div>$1</div>' : '$1');
		}
		elseif (isset($tag[Codes::ATTR_DISABLED_BEFORE]) || isset($tag[Codes::ATTR_DISABLED_AFTER]))
		{
			$tag[Codes::ATTR_BEFORE] = isset($tag[Codes::ATTR_DISABLED_BEFORE]) ? $tag[Codes::ATTR_DISABLED_BEFORE] : (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) ? '<div>' : '');
			$tag[Codes::ATTR_AFTER] = isset($tag[Codes::ATTR_DISABLED_AFTER]) ? $tag[Codes::ATTR_DISABLED_AFTER] : (!empty($tag[Codes::ATTR_BLOCK_LEVEL]) ? '</div>' : '');
		}
		else
		{
			$tag[Codes::ATTR_CONTENT] = $tag[Codes::ATTR_DISABLED_CONTENT];
		}
	}

	// @todo change to returning matches. If array() continue
	protected function matchParameters(array &$possible, &$matches)
	{
		if (!isset($possible['preg_cache']))
		{
			$possible['preg_cache'] = array();
			foreach ($possible[Codes::ATTR_PARAM] as $p => $info)
			{
				$possible['preg_cache'][] = '(\s+' . $p . '=' . (empty($info[Codes::PARAM_ATTR_QUOTED]) ? '' : '&quot;') . (isset($info[Codes::PARAM_ATTR_MATCH]) ? $info[Codes::PARAM_ATTR_MATCH] : '(.+?)') . (empty($info[Codes::PARAM_ATTR_QUOTED]) ? '' : '&quot;') . ')' . (empty($info[Codes::PARAM_ATTR_OPTIONAL]) ? '' : '?');
			}
			$possible['preg_size'] = count($possible['preg_cache']) - 1;
			$possible['preg_keys'] = range(0, $possible['preg_size']);
		}

		$preg = $possible['preg_cache'];
		$param_size = $possible['preg_size'];
		$preg_keys = $possible['preg_keys'];

		// Okay, this may look ugly and it is, but it's not going to happen much and it is the best way
		// of allowing any order of parameters but still parsing them right.
		//$param_size = count($possible['preg_cache']) - 1;
		//$preg_keys = range(0, $param_size);
		$message_stub = substr($this->message, $this->pos1 - 1);

		// If an addon adds many parameters we can exceed max_execution time, lets prevent that
		// 5040 = 7, 40,320 = 8, (N!) etc
		$max_iterations = 5040;

		// Step, one by one, through all possible permutations of the parameters until we have a match
		do {
			$match_preg = '~^';
			foreach ($preg_keys as $key)
			{
				$match_preg .= $possible['preg_cache'][$key];
			}
			$match_preg .= '\]~i';

			// Check if this combination of parameters matches the user input
			$match = preg_match($match_preg, $message_stub, $matches) !== 0;

		} while (!$match && --$max_iterations && ($preg_keys = pc_next_permutation($preg_keys, $param_size)));

		return $match;
	}

	// This allows to parse BBC in parameters like [quote author="[url]www.quotes.com[/quote]"]Something famous.[/quote]
	protected function recursiveParser(&$data, $tag)
	{
		// @todo if parsed tags allowed is empty, return?
//var_dump('handleParsedEquals', $this->message);
//$data = parse_bbc($data, !empty($tag[Codes::ATTR_PARSED_TAGS_ALLOWED]) ? false : true, '', !empty($tag[Codes::ATTR_PARSED_TAGS_ALLOWED]) ? $tag[Codes::ATTR_PARSED_TAGS_ALLOWED] : array());
//$data = parse_bbc($data);
//parse_bbc('dummy');
//return $data;
//var_dump($tag[Codes::ATTR_PARSED_TAGS_ALLOWED], $this->bbc->getTags());die;

		$bbc = clone $this->bbc;
		//$old_bbc = $this->bbc->getForParsing();

		if (!empty($tag[Codes::ATTR_PARSED_TAGS_ALLOWED]))
		{
			foreach ($this->bbc->getTags() as $code)
			{
				if (!in_array($code, $tag[Codes::ATTR_PARSED_TAGS_ALLOWED]))
				{
					$this->bbc->removeTag($code);
				}
			}
		}

		//$this->bbc_codes = $this->bbc->getForParsing();

		$parser = new \BBC\Parser($bbc);
		$data = $parser->parse($data);
		//$data = $this->parse($data);

		// set it back
		//$this->bbc_codes = $old_bbc;
	}

	protected function addOpenTag($tag)
	{
		$this->open_tags[] = $tag;
	}

	// if false, close the last one
	protected function closeOpenedTag($tag = false)
	{
		if ($tag === false)
		{
			//$return = end($this->open_tags);
			//unset($this->open_tags[key($this->open_tags)]);
			//return $return;

			return array_pop($this->open_tags);
		}
		elseif (isset($this->open_tags[$tag]))
		{
			$return = $this->open_tags[$tag];
			unset($this->open_tags[$tag]);
			return $return;
		}
	}

	protected function hasOpenTags()
	{
		return !empty($this->open_tags);
	}

	protected function getLastOpenedTag()
	{
		return end($this->open_tags);
	}

	protected function getOpenedTags($tags_only = false)
	{
		if (!$tags_only)
		{
			return $this->open_tags;
		}

		$tags = array();
		foreach ($this->open_tags as $tag)
		{
			$tags[] = $tag[Codes::ATTR_TAG];
		}
		return $tags;
	}

	protected function trimWhiteSpace(&$message, $offset = null)
	{
		/*

		OUTSIDE
			if ($tag[Codes::ATTR_TRIM] != 'inside' && preg_match('~(<br />|&nbsp;|\s)*~', substr($message, $pos), $matches) != 0)
				$message = substr($message, 0, $pos) . substr($message, $pos + strlen($matches[0]));
			if ($tag[Codes::ATTR_TRIM] != 'inside' && preg_match('~(<br />|&nbsp;|\s)*~', substr($message, $pos), $matches) != 0)
				$message = substr($message, 0, $pos) . substr($message, $pos + strlen($matches[0]));

		INSIDE
			if ($tag[Codes::ATTR_TRIM] != 'outsid' && preg_match('~(<br />|&nbsp;|\s)*~', substr($message, $pos), $matches) != 0)
				$message = substr($message, 0, $pos) . substr($message, $pos + strlen($matches[0]));

		*/

		if (preg_match('~(<br />|&nbsp;|\s)*~', $this->message, $matches, null, $offset) !== 0 && isset($matches[0]))
		{
			//$this->message = substr($this->message, 0, $this->pos) . substr($this->message, $this->pos + strlen($matches[0]));
			$this->message = substr_replace($this->message, '', $this->pos, strlen($matches[0]));
		}
	}

	protected function insertAtCursor($string, $offset)
	{
		$this->message = substr_replace($this->message, $string, $offset, 0);
	}

	protected function removeChars($offset, $length)
	{
		$this->message = substr_replace($this->message, '', $offset, $length);
	}

	protected function setupTagParameters($possible, $matches)
	{
		$params = array();
		for ($i = 1, $n = count($matches); $i < $n; $i += 2)
		{
			$key = strtok(ltrim($matches[$i]), '=');

			if (isset($possible[Codes::ATTR_PARAM][$key][Codes::PARAM_ATTR_VALUE]))
			{
				$params['{' . $key . '}'] = strtr($possible[Codes::ATTR_PARAM][$key][Codes::PARAM_ATTR_VALUE], array('$1' => $matches[$i + 1]));
			}
			// @todo it's not validating it. it is filtering it
			elseif (isset($possible[Codes::ATTR_PARAM][$key][Codes::ATTR_VALIDATE]))
			{
				$params['{' . $key . '}'] = $possible[Codes::ATTR_PARAM][$key][Codes::ATTR_VALIDATE]($matches[$i + 1]);
			}
			else
			{
				$params['{' . $key . '}'] = $matches[$i + 1];
			}

			// Just to make sure: replace any $ or { so they can't interpolate wrongly.
			$params['{' . $key . '}'] = str_replace(array('$', '{'), array('&#036;', '&#123;'), $params['{' . $key . '}']);
		}

		foreach ($possible[Codes::ATTR_PARAM] as $p => $info)
		{
			if (!isset($params['{' . $p . '}']))
			{
				$params['{' . $p . '}'] = '';
			}
		}

		// We found our tag
		$tag = $possible;

		// Put the parameters into the string.
		if (isset($tag[Codes::ATTR_BEFORE]))
		{
			$tag[Codes::ATTR_BEFORE] = strtr($tag[Codes::ATTR_BEFORE], $params);
		}
		if (isset($tag[Codes::ATTR_AFTER]))
		{
			$tag[Codes::ATTR_AFTER] = strtr($tag[Codes::ATTR_AFTER], $params);
		}
		if (isset($tag[Codes::ATTR_CONTENT]))
		{
			$tag[Codes::ATTR_CONTENT] = strtr($tag[Codes::ATTR_CONTENT], $params);
		}

		$this->pos1 += strlen($matches[0]) - 1;

		return $tag;
	}

	protected function isOpen($tag)
	{
		foreach ($this->open_tags as $open)
		{
			if ($open[Codes::ATTR_TAG] === $tag)
			{
				return true;
			}
		}

		return false;
	}

	protected function isItemCode($char)
	{
		return isset($this->item_codes[$char]);
	}

	protected function closeNonBlockLevel()
	{
		$n = count($this->open_tags) - 1;
		while (empty($this->open_tags[$n][Codes::ATTR_BLOCK_LEVEL]) && $n >= 0)
		{
			$n--;
		}

		// Close all the non block level tags so this tag isn't surrounded by them.
		for ($i = count($this->open_tags) - 1; $i > $n; $i--)
		{
			//$this->message = substr_replace($this->message, "\n" . $this->open_tags[$i][Codes::ATTR_AFTER] . "\n", $this->pos, 0);
			$tmp = $this->noSmileys($this->open_tags[$i][Codes::ATTR_AFTER]);
			$this->message = substr_replace($this->message, $tmp, $this->pos, 0);
			//$ot_strlen = strlen($this->open_tags[$i][Codes::ATTR_AFTER]);
			$ot_strlen = strlen($tmp);
			//$this->pos += $ot_strlen + 2;
			$this->pos += $ot_strlen;
			//$this->pos1 += $ot_strlen + 2;
			$this->pos1 += $ot_strlen;

			// Trim or eat trailing stuff... see comment at the end of the big loop.
			if (!empty($this->open_tags[$i][Codes::ATTR_BLOCK_LEVEL]) && substr_compare($this->message, '<br />', $this->pos, 6) === 0)
			{
				$this->message = substr_replace($this->message, '', $this->pos, 6);
			}

			if (isset($tag[Codes::ATTR_TRIM]) && $tag[Codes::ATTR_TRIM] !== Codes::TRIM_INSIDE)
			{
				$this->trimWhiteSpace($this->message, $this->pos);
			}

			$this->closeOpenedTag();
		}
	}

	protected function noSmileys($string)
	{
		return "\n" . $string . "\n";
	}

	protected function parseSmileys($string)
	{

		if ($this->do_smileys === true)
		{
			$old_string = $string;
			parseSmileys($string);
			if ($string != $old_string)
				var_dump($this->message);
		}

		//$string = "\n" . $string . "\n";

		return $string;
	}

	protected function tokenize($message)
	{
		$split_string = $this->getTokenRegex();

		$msg_parts = preg_split($split_string, $message, null, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

		var_dump(
		//$this->bbc_codes,
		//array_keys($this->bbc_codes),
		//$this->bbc->getTags(),
		//$split_chars,
			$split_string,
			$msg_parts
		);

		return $msg_parts;
	}

	protected function getTokenRegex()
	{
		// @todo itemcodes should be ([\n \t;>][itemcode])
		$split_chars = array('(' . preg_quote(']') . ')');

		// Get a list of just tags
		$tags = $this->bbc->getTags();

		// Sort the tags by their length
		usort($tags, function ($a, $b) {
			// @todo micro-optimization but we could store the lengths of the tags as the val and make the tag the key. Then sort on the key
			return strlen($b) - strlen($a);
		});

		foreach ($tags as $bbc)
		{
			$split_chars[] = '(' . preg_quote('[' . $bbc) . ')';
			// Closing tags are easy. They must have [/.*]
			$split_chars[] = '(' . preg_quote('[/' . $bbc) . '])';
		}

		var_dump($tags);

		return '~' . implode('|', $split_chars) . '~';
	}
}