<?php
/**
 * Parser for package.xml version 1.0
 */
class PEAR_PackageFile_Parser_PHP4_v1
{
    /**
     * BC hack to allow PEAR_Common::infoFromString() to sort of
     * work with the version 2.0 format
     * @param PEAR_PackageFile_v2
     */
    function fromV2($packagefile)
    {
    }

    /**
     * @param string contents of package.xml file, version 1.0
     * @return bool success of parsing
     */
    function parse($data, $state, $file)
    {
        require_once('PEAR/Dependency.php');
        if (PEAR_Dependency::checkExtension($error, 'xml')) {
            $this->_stack->push(PEAR_PACKAGEFILE_ERROR_NO_XML_EXT, 'exception', array('error' => $error));
            return $this->_isValid = false;
        }
        $xp = @xml_parser_create();
        if (!$xp) {
            $this->_stack->push(PEAR_PACKAGEFILE_ERROR_CANT_MAKE_PARSER, 'exception');
            return $this->_isValid = false;
        }
        xml_set_object($xp, $this);
        xml_set_element_handler($xp, '_element_start_1_0', '_element_end_1_0');
        xml_set_character_data_handler($xp, '_pkginfo_cdata_1_0');
        xml_parser_set_option($xp, XML_OPTION_CASE_FOLDING, false);

        $this->element_stack = array();
        $this->_packageInfo = array('provides' => array());
        $this->current_element = false;
        unset($this->dir_install);
        $this->_packageInfo['filelist'] = array();
        $this->filelist =& $this->_packageInfo['filelist'];
        $this->dir_names = array();
        $this->in_changelog = false;
        $this->d_i = 0;
        $this->cdata = '';
        $this->_isValid = true;

        if (!xml_parse($xp, $data, 1)) {
            $code = xml_get_error_code($xp);
            $msg = sprintf("XML error: %s at line %d",
                           $str = xml_error_string($code),
                           $line = xml_get_current_line_number($xp));
            xml_parser_free($xp);
            $this->_stack->push(PEAR_PACKAGEFILE_ERROR_PARSER_ERROR, 'error',
                array('error' => $msg, 'code' => $code, 'message' => $str, 'line' => $line));
            return false;
        }

        xml_parser_free($xp);

        foreach ($this->_packageInfo as $k => $v) {
            if (!is_array($v)) {
                $this->_packageInfo[$k] = trim($v);
            }
        }
        $pf = new PEAR_PackageFile_PHP4_v1;
        $pf->fromArray($this->_packageInfo);
        if (!$pf->validate()) {
            return PEAR::raiseError("Parsing of package.xml in file '$file' failed");
        }
        return $pf;
    }

    // Support for package DTD v1.0:
    // {{{ _element_start_1_0()

    /**
     * XML parser callback for ending elements.  Used for version 1.0
     * packages.
     *
     * @param resource  $xp    XML parser resource
     * @param string    $name  name of ending element
     *
     * @return void
     *
     * @access private
     */
    function _element_start_1_0($xp, $name, $attribs)
    {
        array_push($this->element_stack, $name);
        $this->current_element = $name;
        $spos = sizeof($this->element_stack) - 2;
        $this->prev_element = ($spos >= 0) ? $this->element_stack[$spos] : '';
        $this->current_attributes = $attribs;
        $this->cdata = '';
        switch ($name) {
            case 'dir':
                if ($this->in_changelog) {
                    break;
                }
                if ($attribs['name'] != '/') {
                    $this->dir_names[] = $attribs['name'];
                }
                if (isset($attribs['baseinstalldir'])) {
                    $this->dir_install = $attribs['baseinstalldir'];
                }
                if (isset($attribs['role'])) {
                    $this->dir_role = $attribs['role'];
                }
                break;
            case 'file':
                if ($this->in_changelog) {
                    break;
                }
                if (isset($attribs['name'])) {
                    $path = '';
                    if (count($this->dir_names)) {
                        foreach ($this->dir_names as $dir) {
                            $path .= $dir . DIRECTORY_SEPARATOR;
                        }
                    }
                    $path .= $attribs['name'];
                    unset($attribs['name']);
                    $this->current_path = $path;
                    $this->filelist[$path] = $attribs;
                    // Set the baseinstalldir only if the file don't have this attrib
                    if (!isset($this->filelist[$path]['baseinstalldir']) &&
                        isset($this->dir_install))
                    {
                        $this->filelist[$path]['baseinstalldir'] = $this->dir_install;
                    }
                    // Set the Role
                    if (!isset($this->filelist[$path]['role']) && isset($this->dir_role)) {
                        $this->filelist[$path]['role'] = $this->dir_role;
                    }
                }
                break;
            case 'replace':
                if (!$this->in_changelog) {
                    $this->filelist[$this->current_path]['replacements'][] = $attribs;
                }
                break;
            case 'maintainers':
                $this->_packageInfo['maintainers'] = array();
                $this->m_i = 0; // maintainers array index
                break;
            case 'maintainer':
                // compatibility check
                if (!isset($this->_packageInfo['maintainers'])) {
                    $this->_packageInfo['maintainers'] = array();
                    $this->m_i = 0;
                }
                $this->_packageInfo['maintainers'][$this->m_i] = array();
                $this->current_maintainer =& $this->_packageInfo['maintainers'][$this->m_i];
                break;
            case 'changelog':
                $this->_packageInfo['changelog'] = array();
                $this->c_i = 0; // changelog array index
                $this->in_changelog = true;
                break;
            case 'release':
                if ($this->in_changelog) {
                    $this->_packageInfo['changelog'][$this->c_i] = array();
                    $this->current_release = &$this->_packageInfo['changelog'][$this->c_i];
                } else {
                    $this->current_release = &$this->_packageInfo;
                }
                break;
            case 'deps':
                if (!$this->in_changelog) {
                    $this->_packageInfo['release_deps'] = array();
                }
                break;
            case 'dep':
                // dependencies array index
                if (!$this->in_changelog) {
                    $this->d_i++;
                    isset($attribs['type']) ? ($attribs['type'] = strtolower($attribs['type'])) : false;
                    $this->_packageInfo['release_deps'][$this->d_i] = $attribs;
                }
                break;
            case 'configureoptions':
                if (!$this->in_changelog) {
                    $this->_packageInfo['configure_options'] = array();
                }
                break;
            case 'configureoption':
                if (!$this->in_changelog) {
                    $this->_packageInfo['configure_options'][] = $attribs;
                }
                break;
            case 'provides':
                if (empty($attribs['type']) || empty($attribs['name'])) {
                    break;
                }
                $attribs['explicit'] = true;
                $this->_packageInfo['provides']["$attribs[type];$attribs[name]"] = $attribs;
                break;
            case 'package' :
                if (isset($attribs['version'])) {
                    $this->_packageInfo['xsdversion'] = $attribs['version'];
                } else {
                    $this->_packageInfo['xsdversion'] = '1.0';
                }
                break;
        }
    }

    // }}}
    // {{{ _element_end_1_0()

    /**
     * XML parser callback for ending elements.  Used for version 1.0
     * packages.
     *
     * @param resource  $xp    XML parser resource
     * @param string    $name  name of ending element
     *
     * @return void
     *
     * @access private
     */
    function _element_end_1_0($xp, $name)
    {
        $data = trim($this->cdata);
        switch ($name) {
            case 'name':
                switch ($this->prev_element) {
                    case 'package':
                        $this->_packageInfo['package'] = $data;
                        break;
                    case 'maintainer':
                        $this->current_maintainer['name'] = $data;
                        break;
                }
                break;
            case 'summary':
                $this->_packageInfo['summary'] = $data;
                break;
            case 'description':
                $data = $this->_unIndent($this->cdata);
                $this->_packageInfo['description'] = $data;
                break;
            case 'user':
                $this->current_maintainer['handle'] = $data;
                break;
            case 'email':
                $this->current_maintainer['email'] = $data;
                break;
            case 'role':
                $this->current_maintainer['role'] = $data;
                break;
            case 'version':
                //$data = ereg_replace ('[^a-zA-Z0-9._\-]', '_', $data);
                if ($this->in_changelog) {
                    $this->current_release['version'] = $data;
                } else {
                    $this->_packageInfo['version'] = $data;
                }
                break;
            case 'date':
                if ($this->in_changelog) {
                    $this->current_release['release_date'] = $data;
                } else {
                    $this->_packageInfo['release_date'] = $data;
                }
                break;
            case 'notes':
                // try to "de-indent" release notes in case someone
                // has been over-indenting their xml ;-)
                $data = $this->_unIndent($this->cdata);
                if ($this->in_changelog) {
                    $this->current_release['release_notes'] = $data;
                } else {
                    $this->_packageInfo['release_notes'] = $data;
                }
                break;
            case 'warnings':
                if ($this->in_changelog) {
                    $this->current_release['release_warnings'] = $data;
                } else {
                    $this->_packageInfo['release_warnings'] = $data;
                }
                break;
            case 'state':
                if ($this->in_changelog) {
                    $this->current_release['release_state'] = $data;
                } else {
                    $this->_packageInfo['release_state'] = $data;
                }
                break;
            case 'license':
                if ($this->in_changelog) {
                    $this->current_release['release_license'] = $data;
                } else {
                    $this->_packageInfo['release_license'] = $data;
                }
                break;
            case 'dep':
                if ($data && !$this->in_changelog) {
                    $this->_packageInfo['release_deps'][$this->d_i]['name'] = $data;
                }
                break;
            case 'dir':
                if ($this->in_changelog) {
                    break;
                }
                array_pop($this->dir_names);
                break;
            case 'file':
                if ($this->in_changelog) {
                    break;
                }
                if ($data) {
                    $path = '';
                    if (count($this->dir_names)) {
                        foreach ($this->dir_names as $dir) {
                            $path .= $dir . DIRECTORY_SEPARATOR;
                        }
                    }
                    $path .= $data;
                    $this->filelist[$path] = $this->current_attributes;
                    // Set the baseinstalldir only if the file don't have this attrib
                    if (!isset($this->filelist[$path]['baseinstalldir']) &&
                        isset($this->dir_install))
                    {
                        $this->filelist[$path]['baseinstalldir'] = $this->dir_install;
                    }
                    // Set the Role
                    if (!isset($this->filelist[$path]['role']) && isset($this->dir_role)) {
                        $this->filelist[$path]['role'] = $this->dir_role;
                    }
                }
                break;
            case 'maintainer':
                if (empty($this->_packageInfo['maintainers'][$this->m_i]['role'])) {
                    $this->_packageInfo['maintainers'][$this->m_i]['role'] = 'lead';
                }
                $this->m_i++;
                break;
            case 'release':
                if ($this->in_changelog) {
                    $this->c_i++;
                }
                break;
            case 'changelog':
                $this->in_changelog = false;
                break;
        }
        array_pop($this->element_stack);
        $spos = sizeof($this->element_stack) - 1;
        $this->current_element = ($spos > 0) ? $this->element_stack[$spos] : '';
        $this->cdata = '';
    }

    // }}}
    // {{{ _pkginfo_cdata_1_0()

    /**
     * XML parser callback for character data.  Used for version 1.0
     * packages.
     *
     * @param resource  $xp    XML parser resource
     * @param string    $name  character data
     *
     * @return void
     *
     * @access private
     */
    function _pkginfo_cdata_1_0($xp, $data)
    {
        if (isset($this->cdata)) {
            $this->cdata .= $data;
        }
    }

    // }}}
}
?>