
.. _changelog:

Changelog
=========

apart from various bugfixes, the different versions introduced the following
features

  - ``v0.11``

    - added chapter :ref:`hacking` to documentation
    - added ``plugins/doughnut.php`` and :ref:`plugin development tutorial
      <developing-plugin>`
    - added :ref:`labeler`
    - moved source to http://github.com/SwissTPH/odk_planner

  - ``v0.10``

    - redirect after login to avoid repeated POST requests
    - added ``.htaccess`` for pretty URL :ref:`landing pages <install-instance>`
    - updated docs with `GitHub link <https://github.com/SwissTPH/odk_planner>`_
    - disable saving of passwords
    - improved support for mobile

  - ``v0.9``

    - added :ref:`tutorial <tutorial>` with test forms and test data
    - added :ref:`automated testing <testing>`
    - :ref:`instances <install-instance>` are now mandatory; added script to
      create new instance from template or existing
    - new :ref:`instance <install-instance>` first uses temporary random
      password
    - improved :ref:`condition parsing <condition-format>`
    - added :ref:`personalized sms <config-sms>`

  - ``v0.8``

    - introduced :ref:`rich expression syntax <colors-sheet>`
    - :ref:`overview-sheet` has new columns ``condition`` and ``subheading``
      that allow to spread overviews over multiple pages or to have
      multiple overviews in same page, depending on ID and arbitrary
      conditions; row header (subject ID cell) can be styled using ``*``
      as ``form2``

  - ``v0.7``

    - multiple overviews possible for same ID selection
    - better cxrv plugin (adapted for firefox, tablet)
    - better display form content on mobile devices

  - ``v0.6``

    - parse ``_form_data_model`` table from database instead of guessing the
      relationship between database tables and the ``.xls`` forms
    - added ``plugins/cxrv`` to view X-rays

  - ``v0.5``

    - renamed ``routo`` (plugin, docs, config sheet) to ``sms``, now supporting
      different messaging APIs
    - added ``proxy`` setting to ``config.ini``

  - ``v0.4``

    - allow :ref:`multiple instances <install-instance>`
    - added :ref:`ODK pusher <odk-pusher>` for automatic uploading of form data
    - allow to specify :ref:`any field <settings-sheet>` as ``datefield``
    - improved readability (repeated form names, groups navigation menu, limit
      to three browser tabs)

  - ``v0.3``

    - moved MySQL server settings into ``config/config.ini`` (see 
      :ref:`multiple instances <install-instance>`)
    - improved error reporting (from php as well as javascript)
    - separated SMS related code into ``plugins/routo.php``
    - added ``cron.php`` for :ref:`autonomous behavior <automatization>`
    - added ``plugins/cron_reports.php`` for emailing of reports
    - pagination for admin view of :ref:`long log files <log-files>`

  - ``v0.2``

    - download lists as ``.csv`` (see :ref:`csv-generation`)
    - updated :ref:`coloring <colors-sheet>` so that the ``CSS`` styling
      instructions can be based to arbitrary conditions

