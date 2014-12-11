SELECT

--<columns>
      [analyte_result].[test_ID]
      ,[analyte_index]  -- 0=ProbeD, 1=ProbeC, 2=ProbeE, 3=ProbeB, 4=SPC, 5=probeA, 6=QC-1, 7=QC-2, 
      ,[analyte_result].[expected_result]  -- Not sure
      ,[endpoint] 
      ,[threshold]-- Not sure
      ,[cycle_threshold]  --Ct
      ,[probe_check_result]  -- I think 3= 'Pass', other optons need to be checked
      ,[probe_check_reading1]
      ,[probe_check_reading2]
      ,[probe_check_reading3]
      ,[analyte_result].[error_status]-- Not sure
      ,[target_result]-- Not sure
      ,[ic_result]-- Not sure
      ,[ec_result]-- Not sure
      ,[spc_result]-- Not sure
      ,[interpretation]  -- Not sure
      ,[second_deriv_peak_height]  -- Not sure
      ,[nc_ic_endpoint]  -- Not sure
      ,[curve_fit_result]  -- Not sure
      ,[delta_ct]  -- Not sure
      ,[TTP]  -- Not sure
      ,[quantitative_result]  -- Not sure
      
      ,[patient_ID]
      ,[sample_ID]
--</columns>
      
  FROM [dbo].[analyte_result]
  LEFT JOIN [dbo].[test] ON analyte_result.test_ID=test.test_ID
  
  WHERE (sample_ID LIKE '13-%' AND patient_ID LIKE 'G-%')
        AND ({where})

