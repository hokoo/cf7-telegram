# What is wpPostAble

Library provides a functionality for associating your models with WordPress WP_Post model.
Once you create the instance, wpPostAble creates the WP_Post object and stores it in your instance.

You can manage your instance with such methods as

- `$instance->getTitle();`
- `$instance->setTitle();`
- `$instance->getMetaField();`
- `$instance->setMetaField();`
- `$instance->getStatus();`
- `$instance->setStatus();`
- `$instance->getPost();`
- `$instance->getPostType();`
- `$instance->savePost();`
- `$instance->loadPost();`
- `$instance->publish();`
- `$instance->draft();`

and others.

Use 

- `$instance->getParam();`
- `$instance->setParam();`

method to manage metafields, stored inside `posts` table using `post_content_filtered` field.

# How to use

1. Create your own class based on wpPostAble interface

    ```php
   use iTRON\wpPostAble\wpPostAble;
   use iTRON\wpPostAble\wpPostAbleTrait;
   use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
   use iTRON\wpPostAble\Exceptions\wppaLoadPostException;
    
   class Item implements wpPostAble {
      use wpPostAbleTrait;
      
      const POST_TYPE = 'item';
   }
    ```

2. Call `wpPostAble()` method in the beginning of the `__construct()` method of your class.

   Pass to it two parameters

   `$post_type` _string_ WP post type, associated with your class

   `$post_id`   _int_    Post ID for existing post, or nothing for creating new post

   ```php
      /**
       * @throws Exception\wppaLoadPostException
       * @throws Exception\wppaCreatePostException
       */
      public function __construct( int|null $post_id = null ) {
         $this->wpPostAble( self::POST_TYPE, (int) $post_id );
         
         // Do anything you need
      }
   ```

## Now you are able to use your class

Create new post

```php
$item = new Item();
```

or load from existing one

```php
$item = new Item( $post_id );
```


Once you create an instance, wpPostAble creates new post in WordPress as a draft.

Let's try change the title
```php
$item->setTitle('The best item');
```
Now you have set title, and let's try to save it in database
```php
$item->savePost();
```

Maybe it's time to publish?
```php
$item->publish();
```

You can do it by single line
```php
$item->setTitle('The best item')->publish();
```

More options you can find in the description above and in the source code.
