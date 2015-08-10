
.. _hacking:

Hacking
=======

I'm not a fan of PHP. The language has `many shortcomings`_ that make it
difficult to write nicely encapsulated code. I didn't even try but started the
project with one big ``index.php`` file that included everything, and then
refractored some code later on into separate ``.php`` files to prevent the file
from bloating above 1000 lines of code.

.. _many shortcomings: http://eev.ee/blog/2012/04/09/php-a-fractal-of-bad-design/


.. _hacking-overview:

Overview
--------

The file ``index.php`` contains all the logic and structured into the following
sections (VIM_ can expand/collapse the corresponding code folds).

1. Pre-initialization, includes : Things common to all
   :ref:`instances <install-instance>`.

2. Set up instance : Find out form cookies or parameters which instance should
   be used and set up paths accordingly.

3. Load configuration from ``config.xls`` into global ``$user``. See file
   ``config.php``.

4. Login: Show the login box if the user is not currently logged in and compare
   username and password to configuration options during login action. Then
   load user information (access rights etc) into global ``$user``.

5. Connect to database, load forms. The excel forms from the directory
   ``instances/XXX/forms`` are loaded and compared with the local ODK database.
   See file``odk_form.php``.

6. Actions : Do stuff, such as file upload or download. Note that so far no
   HTML output has been generated (if the user is logged in).

7. Start HTML output

8. Show the menu, depending on user's access rights.

9. Display : Generate the main output

   a) ``show=overview`` : Show the tabular overview over all data contained
      in the database. See ``overview.php``.

   b) ``show=form`` : Show the content of a form

   c) ``show=forms`` : List the contents of the ``instances/XXX/forms``
      directory.

   d) ``show=admin`` : Administrator view with upload/download of ``config.xls``

10. Footer : Show some more information if ``&test`` is specified as a URL
    parameter and the current user has ``test`` rights.

The file ``cron.php`` finally contains code that can be run without any user
interaction and is normally executed from the operating system's :ref:`job
scheduler <installing-cron>`.

.. _VIM: http://www.vim.org/download.php


.. _globals-utilities:

Globals and Utilities
---------------------

The following globals are noteworthy

- ``$config`` : An ``ExcelConfig`` object -- see file ``config.php``. This
  object is generated from the file ``instances/XXX/config/config.xls``

- ``$user`` : An array that is set to one of the values in ``$config->users``
  after successful login. With keys ``rights`` and ``access`` that describe
  this users access permissions.

- ``$forms`` : An ``OdkDirectory`` object -- see file ``odk_form.php``. This
  object is generated from the files ``instances/XXX/forms/*.xls``. Every
  object ``OdkForm`` in the dictionary ``$forms->forms`` contains information
  about the form as described in its ``.xls`` file and can be used to read the
  data from the database. See :ref:`accessing-db`.

- ``$show`` : The name of the current view (see above in :ref:`Overview
  <hacking-overview>`).

- ``$hooks`` : Plugins use this object in order to :ref:`install hooks
  <developing-plugin>`.

And some utility functions

- ``log_add($name, $message)`` : Adds a message to the specified :ref:`log file
  <log-files>`.

- ``alert($html, $class)`` : Displays the html snippet inside an alert box with
  the given class (success, info, danger, error).

- ``profile_start($name)`` and ``profile_stop($name)`` : Measure time spent
  for ``$name`` (can be called multiple times, e.g. inside a recursive
  function). The footer displays the total time spent in every ``$name``
  (if the ``&test`` URL parameter is specified).


.. _accessing-db:

Accessing the Database
----------------------

The file ``odk_form.php`` provides methods to access data in the ODK database
using identifiers from .xls files that were used as input to XLSForm_. Please
use the file's `API doc <../../api/files/odk_form.html>`_ for reference.

.. _XLSForm: http://opendatakit.org/use/xlsform/


.. _developing-plugin:

Developing a Plugin
-------------------

``odk_planner`` comes with a simple plugin called ``doughnut`` that adds a page
with overview plots of data in the database.  This section will walk you step
by step through the creation of the :ref:`doughnut plugin <viewing-doughnut>`
that is the file ``plugins/doughnut.php``.  The four steps outlined below
build successively on top of each other and introduce every time some new API to
give a quick an dirty introduction to the internals described above.  Note that
for every step the whole source code of the plugin is included.  Overwrite the
file ``plugins/doughnut.php`` with the source listing from the different steps
to see what changes and play around with the code.

The idea of this plugin is pretty simple : Specify fields and values in the
configuration (sheet ``doughnut``) and let the plugin generate `doughnut plots`_
that show the distribution of the field of interest over the whole study
population.

.. _doughnut plots: http://www.chartjs.org/docs/#doughnut-pie-chart

Step 1 : Hooking
~~~~~~~~~~~~~~~~

The plugin needs to hook into the normal program flow at multiple points.  First
it needs to include the javascript plotting library chart.js_ into the header of
the html page.  Then it needs to change the menu ("augment the views") with a
new menu entry ``doughnut``.  And finally it needs to render the plots when the
view ``doughnut`` is active.

The file ``plugins.php`` lists some 10 different hooks together with an
explanation when they are called and the arguments.  In the code below we use
the three hooks ``dump_headers`` (to add chart.js_), ``augment_views`` (to add
the new menu point), and ``display`` (to render a static doughnut plot).

.. _chart.js: http://www.chartjs.org/

.. literalinclude:: _static/doughnut/v1_hooks.php
  :language: php
  :emphasize-lines: 3,7,28,33-35
  :linenos:

Step 2 : Access
~~~~~~~~~~~~~~~

We don't want just any user to see our plugin, but only users with the
``access`` right (as described in :ref:`user configuration <user-sheet>`).
This is easily implemented by checking for the right in the ``$user`` global
that contains the configuration for the currently logged in user.  We add the
check before rendering the plot but also when the menu is constructed so users
without the needed access right will neither see the plugin in the menu nor be
able to access the plot by manually tweaking the URL.

.. literalinclude:: _static/doughnut/v2_access.php
  :language: php
  :emphasize-lines: 9,31
  :linenos:

Step 3 : Configuration
~~~~~~~~~~~~~~~~~~~~~~

This snippet iterates through the configuration key/value pairs in the lines
``54-63``.  Every value is parsed with the new function ``doughnut_config``
(by the way : it's good practise to prepend all functions in the plugin with
the plugins name to avoid name space collisions).  If an error occurs during the
parsing (quite possible since Excel lets you save just about any invalid setting
imaginable) the doughnut is not rendered for that row and an error is displayed
to the user.

The function ``doughnut_config`` expects value that specifies the field of
interest and the form this field can be found in (``FORM\FIELD``).  The form is
then looked up in the global associative array ``$forms->forms`` and the
specified field is verified to exist in the ``$form->mapping`` (read more
:ref:`above <accessing-db>`).  The name of the MySQL table and column that
represent the field are then returned and used in the next step to extract the
data from the MySQL database.

.. literalinclude:: _static/doughnut/v3_config.php
  :language: php
  :emphasize-lines: 28-47,58-59
  :linenos:

Step 4 : Data
~~~~~~~~~~~~~

Having all the necessary hooks, the doughnut plot, some access control and the
configuration parsing in place, the only thing that need to be done is to
connect the plot to the actual data from the database.

The function ``doughnut_query`` constructs a MySQL query using the MySQL table
and column name.  The query has the following form::

  SELECT column AS value, COUNT(column) AS count
  FROM table GROUP BY column ORDER BY column

where ``column`` and ``table`` will be replace with the actual values.  If the
query were generated for the field ``LRF1\COLONY_COUNT`` from the example
dataset, the following table would be the reply from the query:

======== =====
value    count
======== =====
negative 3
1+       2
2+       3
3+       2
======== =====

The function ``doughnot_json`` merely translates the MySQL result to a JSON
in the format expected by chart.js_.  Note that we call ``mysql_query_`` so the
query gets logged (calling the standard ``mysql_query`` would also work fine).
If MySQL returns an error no plot is displayed but the error is shown to the
user by calling ``alert`` (from ``util.php``).

.. literalinclude:: _static/doughnut/v4_data.php
  :language: php
  :emphasize-lines: 7-12,35-39,52
  :linenos:

Step 5 : Bucketing
~~~~~~~~~~~~~~~~~~

Now compare the source from the last step with the plugin as included in the
``plugins/doughnut.php`` (if you overwrite it get it back from github:
doughnut.php_).  The configuration was extended to list possible values after
``FORM\FIELD``.  If these values have the form of a range of numbers (e.g.
``10-20``) then the MySQL query will summarize the values of some ordinal
datapoint into the buckets thus specified.

By the way : there is also a simple test suite for this plugin.  The file
``test/test_doughnut.py`` checks that the access restrictions work as expected,
that the configuration parsing alerts user if invalid values are specified, and
that a new plot can be added modifying the configuration.

.. _doughnut.php: https://github.com/SwissTPH/odk_planner/blob/master/plugins/doughnut.php

