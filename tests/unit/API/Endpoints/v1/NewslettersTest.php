<?php
use Carbon\Carbon;
use Codeception\Util\Fixtures;
use Codeception\Util\Stub;
use Helper\WordPressHooks as WPHooksHelper;
use MailPoet\API\Endpoints\v1\Newsletters;
use MailPoet\API\Response as APIResponse;
use MailPoet\Models\Newsletter;
use MailPoet\Models\NewsletterOptionField;
use MailPoet\Models\NewsletterSegment;
use MailPoet\Models\Segment;
use MailPoet\Models\SendingQueue;
use MailPoet\Newsletter\Scheduler\Scheduler;
use MailPoet\Newsletter\Url;
use MailPoet\Router\Router;

class NewslettersTest extends MailPoetTest {
  function _before() {
    $this->newsletter = Newsletter::createOrUpdate(
      array(
        'subject' => 'My Standard Newsletter',
        'body' => Fixtures::get('newsletter_body_template'),
        'type' => Newsletter::TYPE_STANDARD,
      ));

    $this->post_notification = Newsletter::createOrUpdate(
      array(
        'subject' => 'My Post Notification',
        'body' => Fixtures::get('newsletter_body_template'),
        'type' => Newsletter::TYPE_NOTIFICATION
      ));
  }

  function testItCanGetANewsletter() {
    $router = new Newsletters();

    $response = $router->get(); // missing id
    expect($response->status)->equals(APIResponse::STATUS_NOT_FOUND);
    expect($response->errors[0]['message'])
      ->equals('This newsletter does not exist.');

    $response = $router->get(array('id' => 'not_an_id'));
    expect($response->status)->equals(APIResponse::STATUS_NOT_FOUND);
    expect($response->errors[0]['message'])
      ->equals('This newsletter does not exist.');

    WPHooksHelper::interceptApplyFilters();

    $response = $router->get(array('id' => $this->newsletter->id));
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data)->equals(
      Newsletter::findOne($this->newsletter->id)
        ->withSegments()
        ->withOptions()
        ->asArray()
    );
    $hook_name = 'mailpoet_api_newsletters_get_after';
    expect(WPHooksHelper::isFilterApplied($hook_name))->true();
    expect(WPHooksHelper::getFilterApplied($hook_name)[0])->internalType('array');
  }

  function testItCanSaveANewNewsletter() {
    $newsletter_option_field = NewsletterOptionField::create();
    $newsletter_option_field->name = 'some_option';
    $newsletter_option_field->newsletter_type = Newsletter::TYPE_STANDARD;
    $newsletter_option_field->save();

    $valid_data = array(
      'subject' => 'My First Newsletter',
      'type' => Newsletter::TYPE_STANDARD,
      'options' => array(
        $newsletter_option_field->name => 'some_option_value',
      )
    );

    WPHooksHelper::interceptApplyFilters();
    WPHooksHelper::interceptDoAction();

    $router = new Newsletters();
    $response = $router->save($valid_data);
    $saved_newsletter = Newsletter::filter('filterWithOptions')
      ->findOne($response->data['id']);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data)->equals($saved_newsletter->asArray());
    // newsletter option should be saved
    expect($saved_newsletter->some_option)->equals('some_option_value');

    $hook_name = 'mailpoet_api_newsletters_save_before';
    expect(WPHooksHelper::isFilterApplied($hook_name))->true();
    expect(WPHooksHelper::getFilterApplied($hook_name)[0])->internalType('array');
    $hook_name = 'mailpoet_api_newsletters_save_after';
    expect(WPHooksHelper::isActionDone($hook_name))->true();
    expect(WPHooksHelper::getActionDone($hook_name)[0] instanceof Newsletter)->true();


    $invalid_data = array(
      'subject' => 'Missing newsletter type'
    );

    $response = $router->save($invalid_data);
    expect($response->status)->equals(APIResponse::STATUS_BAD_REQUEST);
    expect($response->errors[0]['message'])->equals('Please specify a type.');
  }

  function testItCanSaveAnExistingNewsletter() {
    $router = new Newsletters();
    $newsletter_data = array(
      'id' => $this->newsletter->id,
      'subject' => 'My Updated Newsletter'
    );

    $response = $router->save($newsletter_data);
    $updated_newsletter = Newsletter::findOne($this->newsletter->id);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data)->equals($updated_newsletter->asArray());
    expect($updated_newsletter->subject)->equals('My Updated Newsletter');
  }

  function testItCanUpdatePostNotificationScheduleUponSave() {
    $newsletter_options = array(
      'intervalType',
      'timeOfDay',
      'weekDay',
      'monthDay',
      'nthWeekDay',
      'schedule'
    );
    foreach($newsletter_options as $option) {
      $newsletter_option_field = NewsletterOptionField::create();
      $newsletter_option_field->name = $option;
      $newsletter_option_field->newsletter_type = Newsletter::TYPE_NOTIFICATION;
      $newsletter_option_field->save();
    }

    $router = new Newsletters();
    $newsletter_data = array(
      'id' => $this->newsletter->id,
      'type' => Newsletter::TYPE_NOTIFICATION,
      'subject' => 'Newsletter',
      'options' => array(
        'intervalType' => Scheduler::INTERVAL_WEEKLY,
        'timeOfDay' => '50400',
        'weekDay' => '1',
        'monthDay' => '0',
        'nthWeekDay' => '1',
        'schedule' => '0 14 * * 1'
      )
    );
    $response = $router->save($newsletter_data);
    $saved_newsletter = Newsletter::filter('filterWithOptions')
      ->findOne($response->data['id']);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data)->equals($saved_newsletter->asArray());

    // schedule should be recalculated when options change
    $newsletter_data['options']['intervalType'] = Scheduler::INTERVAL_IMMEDIATELY;
    $response = $router->save($newsletter_data);
    $saved_newsletter = Newsletter::filter('filterWithOptions')
      ->findOne($response->data['id']);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($saved_newsletter->schedule)->equals('* * * * *');
  }

  function testItCanReschedulePreviouslyScheduledSendingQueueJobs() {
    // create newsletter options
    $newsletter_options = array(
      'intervalType',
      'timeOfDay',
      'weekDay',
      'monthDay',
      'nthWeekDay',
      'schedule'
    );
    foreach($newsletter_options as $option) {
      $newsletter_option_field = NewsletterOptionField::create();
      $newsletter_option_field->name = $option;
      $newsletter_option_field->newsletter_type = Newsletter::TYPE_NOTIFICATION;
      $newsletter_option_field->save();
    }

    // create sending queues
    $current_time = Carbon::now();
    $sending_queue_1 = SendingQueue::create();
    $sending_queue_1->newsletter_id = 1;
    $sending_queue_1->status = SendingQueue::STATUS_SCHEDULED;
    $sending_queue_1->scheduled_at = $current_time;
    $sending_queue_1->save();

    $sending_queue_2 = SendingQueue::create();
    $sending_queue_2->newsletter_id = 1;
    $sending_queue_2->save();

    // save newsletter via router
    $router = new Newsletters();
    $newsletter_data = array(
      'id' => 1,
      'type' => Newsletter::TYPE_NOTIFICATION,
      'subject' => 'Newsletter',
      'options' => array(
        // weekly on Monday @ 7am
        'intervalType' => Scheduler::INTERVAL_WEEKLY,
        'timeOfDay' => '25200',
        'weekDay' => '1',
        'monthDay' => '0',
        'nthWeekDay' => '1',
        'schedule' => '0 7 * * 1'
      )
    );
    $newsletter = $router->save($newsletter_data);
    $sending_queue_1 = SendingQueue::findOne($sending_queue_1->id);
    $sending_queue_2 = SendingQueue::findOne($sending_queue_2->id);
    expect($sending_queue_1->scheduled_at)->notEquals($current_time);
    expect($sending_queue_1->scheduled_at)->equals(
      Scheduler::getNextRunDate($newsletter->data['schedule'])
    );
    expect($sending_queue_2->scheduled_at)->null();
  }

  function testItCanModifySegmentsOfExistingNewsletter() {
    $segment_1 = Segment::createOrUpdate(array('name' => 'Segment 1'));
    $fake_segment_id = 1;

    $router = new Newsletters();
    $newsletter_data = array(
      'id' => $this->newsletter->id,
      'subject' => 'My Updated Newsletter',
      'segments' => array(
        $segment_1->asArray(),
        $fake_segment_id
      )
    );

    $response = $router->save($newsletter_data);
    expect($response->status)->equals(APIResponse::STATUS_OK);

    $updated_newsletter =
      Newsletter::findOne($this->newsletter->id)
        ->withSegments();

    expect(count($updated_newsletter->segments))->equals(1);
    expect($updated_newsletter->segments[0]['name'])->equals('Segment 1');
  }

  function testItCanSetANewsletterStatus() {
    $router = new Newsletters();
    // set status to sending
    $response = $router->setStatus
    (array(
       'id' => $this->newsletter->id,
       'status' => Newsletter::STATUS_SENDING
     )
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data['status'])->equals(Newsletter::STATUS_SENDING);

    // set status to draft
    $response = $router->setStatus(
      array(
        'id' => $this->newsletter->id,
        'status' => Newsletter::STATUS_DRAFT
      )
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data['status'])->equals(Newsletter::STATUS_DRAFT);

    // no status specified throws an error
    $response = $router->setStatus(
      array(
        'id' => $this->newsletter->id,
      )
    );
    expect($response->status)->equals(APIResponse::STATUS_BAD_REQUEST);
    expect($response->errors[0]['message'])
      ->equals('You need to specify a status.');

    // invalid newsletter id throws an error
    $response = $router->setStatus(
      array(
        'status' => Newsletter::STATUS_DRAFT
      )
    );
    expect($response->status)->equals(APIResponse::STATUS_NOT_FOUND);
    expect($response->errors[0]['message'])
      ->equals('This newsletter does not exist.');
  }

  function testItCanRestoreANewsletter() {
    $this->newsletter->trash();

    $trashed_newsletter = Newsletter::findOne($this->newsletter->id);
    expect($trashed_newsletter->deleted_at)->notNull();

    $router = new Newsletters();
    $response = $router->restore(array('id' => $this->newsletter->id));
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data)->equals(
      Newsletter::findOne($this->newsletter->id)
        ->asArray()
    );
    expect($response->data['deleted_at'])->null();
    expect($response->meta['count'])->equals(1);
  }

  function testItCanTrashANewsletter() {
    $router = new Newsletters();
    $response = $router->trash(array('id' => $this->newsletter->id));
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data)->equals(
      Newsletter::findOne($this->newsletter->id)
        ->asArray()
    );
    expect($response->data['deleted_at'])->notNull();
    expect($response->meta['count'])->equals(1);
  }

  function testItCanDeleteANewsletter() {
    $router = new Newsletters();
    $response = $router->delete(array('id' => $this->newsletter->id));
    expect($response->data)->isEmpty();
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->meta['count'])->equals(1);
  }

  function testItCanDuplicateANewsletter() {
    WPHooksHelper::interceptDoAction();

    $router = new Newsletters();
    $response = $router->duplicate(array('id' => $this->newsletter->id));
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data)->equals(
      Newsletter::where('subject', 'Copy of My Standard Newsletter')
        ->findOne()
        ->asArray()
    );
    expect($response->meta['count'])->equals(1);

    $hook_name = 'mailpoet_api_newsletters_duplicate_after';
    expect(WPHooksHelper::isActionDone($hook_name))->true();
    expect(WPHooksHelper::getActionDone($hook_name)[0] instanceof Newsletter)->true();

    $response = $router->duplicate(array('id' => $this->post_notification->id));
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data)->equals(
      Newsletter::where('subject', 'Copy of My Post Notification')
        ->findOne()
        ->asArray()
    );
    expect($response->meta['count'])->equals(1);
  }

  function testItCanCreateANewsletter() {
    $data = array(
      'subject' => 'My New Newsletter',
      'type' => Newsletter::TYPE_STANDARD
    );
    $router = new Newsletters();
    $response = $router->create($data);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data)->equals(
      Newsletter::where('subject', 'My New Newsletter')
        ->findOne()
        ->asArray()
    );

    $response = $router->create();
    expect($response->status)->equals(APIResponse::STATUS_BAD_REQUEST);
    expect($response->errors[0]['message'])->equals('Please specify a type.');
  }

  function testItCanGetListingData() {
    $segment_1 = Segment::createOrUpdate(array('name' => 'Segment 1'));
    $segment_2 = Segment::createOrUpdate(array('name' => 'Segment 2'));

    $newsletter_segment = NewsletterSegment::create();
    $newsletter_segment->hydrate(
      array(
        'newsletter_id' => $this->newsletter->id,
        'segment_id' => $segment_1->id
      )
    );
    $newsletter_segment->save();

    $newsletter_segment = NewsletterSegment::create();
    $newsletter_segment->hydrate(
      array(
        'newsletter_id' => $this->newsletter->id,
        'segment_id' => $segment_2->id
      )
    );
    $newsletter_segment->save();

    $newsletter_segment = NewsletterSegment::create();
    $newsletter_segment->hydrate(
      array(
        'newsletter_id' => $this->post_notification->id,
        'segment_id' => $segment_2->id
      )
    );
    $newsletter_segment->save();

    $router = new Newsletters();
    $response = $router->listing();

    expect($response->status)->equals(APIResponse::STATUS_OK);

    expect($response->meta)->hasKey('filters');
    expect($response->meta)->hasKey('groups');
    expect($response->meta['count'])->equals(2);

    expect($response->data)->count(2);
    expect($response->data[0]['subject'])->equals('My Standard Newsletter');
    expect($response->data[1]['subject'])->equals('My Post Notification');

    // 1st subscriber has 2 segments
    expect($response->data[0]['segments'])->count(2);
    expect($response->data[0]['segments'][0]['id'])
      ->equals($segment_1->id);
    expect($response->data[0]['segments'][1]['id'])
      ->equals($segment_2->id);

    // 2nd subscriber has 1 segment
    expect($response->data[1]['segments'])->count(1);
    expect($response->data[1]['segments'][0]['id'])
      ->equals($segment_2->id);
  }

  function testItCanFilterListing() {
    // create 2 segments
    $segment_1 = Segment::createOrUpdate(array('name' => 'Segment 1'));
    $segment_2 = Segment::createOrUpdate(array('name' => 'Segment 2'));

    // link standard newsletter to the 2 segments
    $newsletter_segment = NewsletterSegment::create();
    $newsletter_segment->hydrate(
      array(
        'newsletter_id' => $this->newsletter->id,
        'segment_id' => $segment_1->id
      )
    );
    $newsletter_segment->save();

    $newsletter_segment = NewsletterSegment::create();
    $newsletter_segment->hydrate
    (array(
       'newsletter_id' => $this->newsletter->id,
       'segment_id' => $segment_2->id
     )
    );
    $newsletter_segment->save();

    // link post notification to the 2nd segment
    $newsletter_segment = NewsletterSegment::create();
    $newsletter_segment->hydrate(
      array(
        'newsletter_id' => $this->post_notification->id,
        'segment_id' => $segment_2->id
      )
    );
    $newsletter_segment->save();

    $router = new Newsletters();

    // filter by 1st segment
    $response = $router->listing(
      array(
        'filter' => array(
          'segment' => $segment_1->id
        )
      )
    );

    expect($response->status)->equals(APIResponse::STATUS_OK);

    // we should only get the standard newsletter
    expect($response->meta['count'])->equals(1);
    expect($response->data[0]['subject'])->equals($this->newsletter->subject);

    // filter by 2nd segment
    $response = $router->listing(
      array(
        'filter' => array(
          'segment' => $segment_2->id
        )
      )
    );

    expect($response->status)->equals(APIResponse::STATUS_OK);

    // we should have the 2 newsletters
    expect($response->meta['count'])->equals(2);
  }

  function testItCanLimitListing() {
    $router = new Newsletters();
    // get 1st page (limit items per page to 1)
    $response = $router->listing(
      array(
        'limit' => 1,
        'sort_by' => 'subject',
        'sort_order' => 'asc'
      )
    );

    expect($response->status)->equals(APIResponse::STATUS_OK);

    expect($response->meta['count'])->equals(2);
    expect($response->data)->count(1);
    expect($response->data[0]['subject'])->equals(
      $this->post_notification->subject
    );

    // get 1st page (limit items per page to 1)
    $response = $router->listing(
      array(
        'limit' => 1,
        'offset' => 1,
        'sort_by' => 'subject',
        'sort_order' => 'asc'
      )
    );

    expect($response->meta['count'])->equals(2);
    expect($response->data)->count(1);
    expect($response->data[0]['subject'])->equals(
      $this->newsletter->subject
    );
  }

  function testItCanBulkDeleteSelectionOfNewsletters() {
    $selection_ids = array(
      $this->newsletter->id,
      $this->post_notification->id
    );

    $router = new Newsletters();
    $response = $router->bulkAction(
      array(
        'listing' => array(
          'selection' => $selection_ids
        ),
        'action' => 'delete'
      )
    );

    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->meta['count'])->equals(count($selection_ids));
  }

  function testItCanBulkDeleteNewsletters() {
    $router = new Newsletters();
    $response = $router->bulkAction(
      array(
        'action' => 'trash',
        'listing' => array('group' => 'all')
      )
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->meta['count'])->equals(2);

    $router = new Newsletters();
    $response = $router->bulkAction(
      array(
        'action' => 'delete',
        'listing' => array('group' => 'trash')
      )
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->meta['count'])->equals(2);

    $response = $router->bulkAction(
      array(
        'action' => 'delete',
        'listing' => array('group' => 'trash')
      )
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->meta['count'])->equals(0);
  }

  function testItCanSendAPreview() {
    $subscriber = 'test@subscriber.com';
    $data = array(
      'subscriber' => $subscriber,
      'id' => $this->newsletter->id,
      'mailer' => Stub::makeEmpty(
        '\MailPoet\Mailer\Mailer',
        array(
          'send' => function($newsletter, $subscriber) {
            expect(is_array($newsletter))->true();
            expect($newsletter['body']['text'])->contains('Hello test');
            expect($subscriber)->equals($subscriber);
            return array('response' => true);
          }
        )
      )
    );
    $router = new Newsletters();
    $response = $router->sendPreview($data);
    expect($response->status)->equals(APIResponse::STATUS_OK);
  }

  function testItReturnsMailerErrorWhenSendingFailed() {
    $subscriber = 'test@subscriber.com';
    $data = array(
      'subscriber' => $subscriber,
      'id' => $this->newsletter->id,
      'mailer' => Stub::makeEmpty(
        '\MailPoet\Mailer\Mailer',
        array(
          'send' => function($newsletter, $subscriber) {
            expect(is_array($newsletter))->true();
            expect($newsletter['body']['text'])->contains('Hello test');
            expect($subscriber)->equals($subscriber);
            return array(
              'response' => false,
              'error_message' => 'failed'
            );
          }
        )
      )
    );
    $router = new Newsletters();
    $response = $router->sendPreview($data);
    expect($response->errors[0]['message'])->equals('The email could not be sent: failed');
  }

  function testItGeneratesPreviewLinksWithNewsletterHashAndNoSubscriberData() {
    $router = new Newsletters();
    $response = $router->listing();
    $preview_link = $response->data[0]['preview_url'];
    parse_str(parse_url($preview_link, PHP_URL_QUERY), $preview_link_data);
    $preview_link_data = Url::transformUrlDataObject(Router::decodeRequestData($preview_link_data['data']));
    expect($preview_link_data['newsletter_hash'])->notEmpty();
    expect($preview_link_data['subscriber_id'])->false();
    expect($preview_link_data['subscriber_token'])->false();
    expect((boolean)$preview_link_data['preview'])->true();
  }

  function _after() {
    WPHooksHelper::releaseAllHooks();
    ORM::raw_execute('TRUNCATE ' . Newsletter::$_table);
    ORM::raw_execute('TRUNCATE ' . NewsletterSegment::$_table);
    ORM::raw_execute('TRUNCATE ' . NewsletterOptionField::$_table);
    ORM::raw_execute('TRUNCATE ' . Segment::$_table);
    ORM::raw_execute('TRUNCATE ' . SendingQueue::$_table);
  }
}