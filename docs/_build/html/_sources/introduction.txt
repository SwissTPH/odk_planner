
Introduction
============


``odk_planner`` is a web application for use in combination with OpenDataKit_,
especially with XLSForm_ and the `Aggregate Server`_.  While it's easy to
export the data in the end, the Aggregate Server interface makes it difficult
to monitor the progress of form submission, especially in complex studies where
many different forms are used and timing between the different forms is
critical.

Example
-------

The main functionality of ``odk_planner`` is to show all submitted form data in
a large :ref:`Overview table <overview-table>`, sorted by study subject id.
Imagine that you have a study with an initial screening form ``CRF1`` that
is followed by a second examination form ``CRF2`` and a lab form ``LRF1``.
When you look into your data using ODK Aggregate, it would be displayed as
three separate tables (the data shown below is taken from the :ref:`tutorial
<tutorial>`)

======== =============== ===============
study_id full_name       completion_date
======== =============== ===============
80001    Alice Armstrong 2014-01-01
80002    Bob Berkeley    2014-01-02
80003    Cindy Chase     2014-01-03
80004    Daniel Death    2014-01-04
80005    Emily Einstein  2014-01-05
80006    Fabian Fox      2014-01-06
======== =============== ===============

======== =========== ===============
study_id temperature completion_date
======== =========== ===============
80001    38.3        2014-01-01
80002    37.3        2014-01-02
80003    37.4        2014-01-03
80004    38.8        2014-01-04
80005    37.6        2014-01-05
======== =========== ===============

============ ========== ===============
study_id     hemoglobin completion_date
============ ========== ===============
80001-V01    14         2014-01-06
80002-V01    8          2014-01-06
80003-V01    9          2014-01-06
80004-V01    6          2014-01-10
80005-V01    13.1       2014-01-10
80006-V01    15.5       2014-01-10
============ ========== ===============

It's obvious that this kind of data display quickly becomes quite confusing --
with thousands of participants and dozens of forms in a real-world scenario...
``odk_planner`` extracts the data from the database and displays a neat
:ref:`overview form <overview-table>`.  For the data above, this would like
this

======== ========== ========== ==========
study_id CRF1       CRF2       LRF1
======== ========== ========== ==========
80001    2014-01-01 2014-01-01 2014-01-06
80002    2014-01-02 2014-01-01 2014-01-06
80003    2014-01-03 2014-01-01 2014-01-06
80004    2014-01-04 2014-01-01 2014-01-10
80005    2014-01-05 2014-01-01 2014-01-10
80006    2014-01-06            2014-01-10
======== ========== ========== ==========


Features
--------

In particular, ``odk_planner`` has the following features

  - cells in the overview table can be :ref:`highlighted <colors-sheet>`
    according to the relative timing to other forms: for example, the empty
    cell ``80006/CRF2`` in the table above could be colored if ``80006/CRF1``
    has been entered a defined number of days ago

  - cells in the overview can also be :ref:`highlighted <colors-sheet>`
    depending on the data of values in the submitted forms: for example, the
    submissions of ``LRF1`` above could be colored if the hemoglobin level is
    outside a specified range

  - the data in the overview table can be used to generate :ref:`printable
    tables <csv-generation>` of missing forms, and these reports of missing
    forms can even automatically be :ref:`sent in emails <config-cron>`

  - participants can be :ref:`notified by SMS <sending-sms>`, based on the
    highlighting of the cells in the overview table; this mechanism can
    also be :ref:`configured <config-sms>` to send data from the forms to
    a specified number

  - all data in the database can be :ref:`viewed <viewing-data>` by clicking
    on the links in the overview table; contrary to ODK Aggregate, you can
    specify :ref:`detailed access permissions <access-example>` for every
    datapoint

  - the schema of the data is read from the same Excel files that are used
    to generate the ``.xml`` forms via XLSForm_

  - to put the cherry on the cake, this web application comes bundled with some
    tools that can be used to :ref:`push data automatically <odk-pusher>` into
    the ODK database; with this mechanism you can for example integrate
    :ref:`integrate X-ray images <xray-uploader>` or :ref:`data from a MS-SQL
    database <mssql-uploader>` -- transparently and fully automatically


Overview
--------

The documentation is structured as follows:

  - :ref:`Installation <installing>` explains how to set up ``odk_planner``;
    in a typical setting, this part of the documentation would only be read
    by the system administrator who also installed the Aggregate server

  - :ref:`Configuration <configuring>` describes how to adapt the configuration
    file ``config.xls`` to your needs

  - :ref:`Using <using>` describes the features in more detail

  - :ref:`Tools <tools>` gives an overview of the additional (Python-based)
    software included in the distribution to automate the integration of data
    from different sources into your Aggregate database

  - :ref:`Tutorial <tutorial>` finally describes a sample setup and comes with
    some sample data that quickly lets you play around with ``odk_planner`` to
    get an idea of its functionality

.. _OpenDataKit: http://opendatakit.org
.. _Aggregate Server: http://opendatakit.org/use/aggregate/
.. _XLSForm: http://xlsform.org/

