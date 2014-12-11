SELECT 
--<columns>
	 [test_ID]
	,[site_name]  -- The slot (Module) on the machine where cartridge is inserted
	,[site_serial_num] -- Serial number of this Module
	,assay.name as AssayName
	,[rgt_lot_ID] --Reagent Lot ID
	,[cart_serial_number] --Cartridge serial number
	,[sample_ID] -- The sample ID entered by user before test
	,[patient_ID]  -- The Patient ID entered by user before test
	,[notes] -- The notes entered by user before test
	,[state] -- Not sure
	,[error_status] 
	,[start_time]
	,[end_time]
	,users.full_name 
	,[sw_version]
	,[icore_serial_num]  --Not sure, but seems important
	,[fw_version] --Not sure, but seems important
	,test.[guid]  -- Maybe usefull, otherwise not needed
	,[instrument_serial_number]
	,[order_time] --Not sure
	,[expiration_date] --Not sure
	,[cartridge_barcode]
	,[result_text] -- Most important field
--</columns>

  FROM [dbo].[test]
  
  LEFT JOIN dbo.users ON test.[user_ID] = users.[user_ID]
  LEFT JOIN assay ON test.assay_ID = assay.assay_ID
  
  WHERE (sample_ID LIKE '13-%' AND patient_ID LIKE 'G-%')
        AND ({where})

