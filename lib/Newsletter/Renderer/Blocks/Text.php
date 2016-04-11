<?php
namespace MailPoet\Newsletter\Renderer\Blocks;

use MailPoet\Newsletter\Renderer\StylesHelper;

class Text {
  static function render($element) {
    $html = $element['text'];
    $html = self::convertBlockquotesToTables($html);
    $html = self::convertParagraphsToTables($html);
    $html = self::styleLists($html);
    $html = self::styleHeadings($html);
    $html = self::removeLastLineBreak($html);
    $template = '
      <tr>
        <td class="mailpoet_text mailpoet_padded_bottom mailpoet_padded_side" valign="top" style="word-break:break-word;word-wrap:break-word;">
          ' . $html . '
        </td>
      </tr>';
    return $template;
  }

  static function convertBlockquotesToTables($html) {
    $DOM_parser = new \pQuery();
    $DOM = $DOM_parser->parseStr($html);
    $blockquotes = $DOM->query('blockquote');
    if(!$blockquotes->count()) return $html;
    foreach($blockquotes as $blockquote) {
      $paragraphs = $blockquote->query('p', 0);
      foreach($paragraphs as $index => $paragraph) {
        $contents[] = $paragraph->html();
        if($index + 1 < $paragraphs->count()) $contents[] = '<br />';
        $paragraph->remove();
      }
      $paragraph->remove();
      $blockquote->setTag('table');
      $blockquote->addClass('mailpoet_blockquote');
      $blockquote->width = '100%';
      $blockquote->spacing = 0;
      $blockquote->border = 0;
      $blockquote->cellpadding = 0;
      $blockquote->html('
        <tbody>
          <tr>
            <td width="2" bgcolor="#565656"></td>
            <td width="10"></td>
            <td valign="top">
              <table style="border-spacing:0;mso-table-lspace:0;mso-table-rspace:0">
                <tr>
                  <td class="mailpoet_blockquote">
                  ' . implode('', $contents) . '
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </tbody>'
      );
      $blockquote->parent->insertChild(
        array(
          'tag_name' => 'br',
          'self_close' => true,
          'attributes' => array()
        ),
        $blockquote->index() + 1
      );
    }
    return $DOM->__toString();
  }

  static function convertParagraphsToTables($html) {
    $DOM_parser = new \pQuery();
    $DOM = $DOM_parser->parseStr($html);
    $paragraphs = $DOM->query('p');
    if(!$paragraphs->count()) return $html;
    foreach($paragraphs as $paragraph) {
      // remove empty paragraphs
      if(!trim($paragraph->html())) {
        $paragraph->remove();
        continue;
      }
      $style = $paragraph->style;
      if(!preg_match('/text-align/i', $style)) {
        $style = 'text-align: left;' . $style;
      }
      $contents = $paragraph->html();
      $paragraph->setTag('table');
      $paragraph->style = 'border-spacing:0;mso-table-lspace:0;mso-table-rspace:0;';
      $paragraph->width = '100%';
      $paragraph->cellpadding = 0;
      $next_element = $paragraph->getNextSibling();
      // unless this is the last element in column, add double line breaks
      $line_breaks = ($next_element && !empty($next_element->getInnerText())) ? '<br /><br />' : '';
      // if this element is followed by a list, add single line break
      $line_breaks = ($next_element && preg_match('/<li>/i', $next_element->getInnerText())) ? '<br />' : $line_breaks;
      $paragraph->html('
        <tr>
          <td class="mailpoet_paragraph" style="word-break:break-word;word-wrap:break-word;' . $style . '">
            ' . $contents . $line_breaks . '
          </td>
         </tr>'
      );
    }
    return $DOM->__toString();
  }

  static function styleLists($html) {
    $DOM_parser = new \pQuery();
    $DOM = $DOM_parser->parseStr($html);
    $lists = $DOM->query('ol, ul, li');
    if(!$lists->count()) return $html;
    foreach($lists as $list) {
      if($list->tag === 'li') {
        $list->setInnertext($list->text());
        $list->class = 'mailpoet_paragraph';
      } else {
        $list->class = 'mailpoet_paragraph';
        $list->style .= 'padding-top:0;padding-bottom:0;margin-top:10px;';
      }
      $list->style = StylesHelper::applyTextAlignment($list->style);
      $list->style .= 'margin-bottom:10px;';
    }
    return $DOM->__toString();
  }

  static function styleHeadings($html) {
    $DOM_parser = new \pQuery();
    $DOM = $DOM_parser->parseStr($html);
    $headings = $DOM->query('h1, h2, h3, h4');
    if(!$headings->count()) return $html;
    foreach($headings as $heading) {
      $heading->style = StylesHelper::applyTextAlignment($heading->style);
      $heading->style .= 'padding:0;font-style:normal;font-weight:normal;';
    }
    return $DOM->__toString();
  }

  static function removeLastLineBreak($html) {
    return preg_replace('/(^)?(<br.*?\/?>)+$/i', '', $html);
  }
}