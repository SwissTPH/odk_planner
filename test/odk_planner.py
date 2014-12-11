'''
some utility functions/definitions for odk_planner test scripts
'''

import os.path, time, re, logging, shutil, stat, pwd, tempfile

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import Select, WebDriverWait
from selenium.common.exceptions import NoSuchElementException
from selenium.common.exceptions import NoAlertPresentException

# some directories/files
odk_planner_dir = os.path.join(os.path.dirname(__file__), os.path.pardir)
instance_dir = os.path.join(odk_planner_dir, 'instances', 'testing')
config_xls_sample = os.path.join(odk_planner_dir, 'config', 'config-sample.xls')
config_ini_sample = os.path.join(odk_planner_dir, 'config', 'config-sample.ini')

lo = logging.getLogger('odk_planner')


# PlannerOverview {{{1

class PlannerOverview:

    ''' helper class to access cells in the overview table '''

    def __init__(self, table):
        self.table = table
        # first entry is //tr/td[1]
        self.cols = [
                th.text for th in
                table.find_elements_by_xpath('//tr[1]/th')
            ]
        # first entry is //tr[2]
        # also contains '' for col header repetitions
        self.rows = [
                th.text for th in
                table.find_elements_by_xpath('//tr[position()>1]/th')
            ]

    def sms_link(self, studyid):
        i = self.rows.index(studyid) + 2
        return self.table.find_element_by_xpath(
                '//tr[%d]/th//i[@class = "icon-envelope"]/ancestor::a' % i)

    def element(self, studyid, formid, link=False):
        i = self.rows.index(studyid) + 2
        if formid is None:
            tdh = 'th'
            j = 1
        else:
            tdh = 'td'
            j = self.cols.index(formid) + 1
        return self.table.find_element_by_xpath('//tr[%d]/%s[%d]%s' %
                (i, tdh, j, link and '//a' or ''))

    def text(self, studyid, formid):
        return self.element(studyid, formid).text

    def css(self, studyid, formid, cssproperty):
        return self.element(studyid, formid).value_of_css_property(cssproperty)


# PlannerDriver {{{1

class PlannerDriver:

    ''' command odk_planner website via webdriver '''

    def __init__(self, url, instance, driver=None):
        self.url = url
        self.instance = instance

        if driver is None:
            # http://stackoverflow.com/questions/1176348
            profile = webdriver.FirefoxProfile()
            profile.set_preference("browser.download.folderList", 2);
            profile.set_preference("browser.download.manager.showWhenStarting", False);
            self.downloads = tempfile.gettempdir()
            profile.set_preference("browser.download.dir", self.downloads);
            mimes = "text/csv,application/vnd.ms-excel"
            profile.set_preference("browser.helperApps.neverAsk.saveToDisk", mimes);
            driver = webdriver.Firefox(profile)

        self.driver = driver
        self.waitsecs = 10

    def close(self):
        self.driver.quit()

    def close_others(self):
        while len(self.driver.window_handles) > 1:
            self.driver.switch_to_window(self.driver.window_handles[-1])
            self.driver.close()
        self.driver.switch_to_window(self.driver.window_handles[0])

    def el_xpath(self, xpath):
        try:
            return self.driver.find_element_by_xpath(xpath)
        except NoSuchElementException:
            return None

    def els_xpath(self, xpath):
        return self.driver.find_elements_by_xpath(xpath)

    def el_xpath_wait(self, xpath, seconds=5):
        return WebDriverWait(self.driver, seconds).until(
                EC.presence_of_element_located((By.XPATH, xpath))
            )

    def el_css(self, css, wait=None):
        try:
            return self.driver.find_element_by_css_selector(css)
        except NoSuchElementException:
            return None

    def els_css(self, css):
        return self.driver.find_elements_by_css_selector(css)

    def el_css_wait(self, css, seconds=5):
        return WebDriverWait(self.driver, seconds).until(
                EC.presence_of_element_located((By.CSS_SELECTOR, css))
            )

    def get(self, uri):
        self.driver.get(self.url + uri)

    def get_title(self):
        self.get('?instance=%s' % self.instance)
        title = self.el_css('.form-signin-title')
        if title:
            return title.text

    def get_error(self):
        self.get('?instance=%s' % self.instance)
        error = self.el_css('.alert-error')
        if error:
            return error.text

    def login(self, user, pwd):
        self.get('?instance=%s' % self.instance)
        self.el_xpath('//input[@name="user"]').send_keys(user)
        self.el_xpath('//input[@name="password"]').send_keys(pwd)
        self.el_xpath('//button[@type="submit"]').click()

    def logout(self):
        self.get('?logout')

    def logged_in(self):
        return not self.el_xpath('//ul[@class = "nav"]') is None

    def menu_names(self):
        return [
                e.text
                for e in self.els_xpath('//ul[@class="nav"]/li/a')
            ]

    def menu_go(self, name):
        self.el_xpath('//ul[@class="nav"]/li/a[. = "%s"]' % name).click()

    def title(self):
        return self.el_xpath('//h4[@class="site-title"]').text


    # overview related {{{2

    def overview_names(self):
        self.el_xpath('id("overtoggle")').click()
        ret = [
                a.text for a in
                self.els_xpath('//ul[@class="nav"]//ul//li/a')
            ]
        self.el_xpath('id("overtoggle")').click()
        return ret

    def overview_go(self, text):
        self.get('')
        self.el_xpath('id("overtoggle")').click()
        self.el_xpath('//ul[@class="nav"]//ul//li/a[. = "%s"]' % text).click()

    def overviews(self):
        xpath = '//table[contains(concat(" ", @class, " "), " overview-table ")]'
        return [
                PlannerOverview(overview)
                for overview in self.els_xpath(xpath)
            ]

    def overview_download(self, overview):
        self.overview_go(overview)
        oldfiles = os.listdir(self.downloads)
        self.el_css('.overview-table .topleft .icon-download-alt').click()
        for i in range(10 * self.waitsecs):
            newfiles = os.listdir(self.downloads)
            if len(newfiles) > len(oldfiles):
                fname = (set(newfiles) - set(oldfiles)).pop()
                return os.path.join(self.downloads, fname)
            time.sleep(100)
        return None


    # sms related {{{2

    def has_mass_sms(self):
        self.overview_go('All')
        return len(self.els_css('.sms-send')) > 0

    def mass_sms_go(self):
        self.overview_go('All')
        self.el_css('.sms-send').click()

    def mass_sms_send(self):
        self.el_css('.select-all').click()
        xpath = '//table//tr[last()]/td[last()]'
        xpath += '/span[contains(concat(" ", @class, " "), " send-status ")]'
        self.el_css('.send-selected').click()
        self.el_xpath_wait(xpath, 5)
        return len(self.els_css('.send-status'))

    def mass_sms_messages(self):
        return [el.text for el in self.els_css('.message')]

    def mass_sms_reset(self):
        self.get('?test_reset_mass_sms')

    def single_sms_available(self):
        self.overview_go(self.overview_names()[0])
        overview = self.overviews()[0]
        try:
            overview.sms_link(overview.rows[0])
            return True
        except NoSuchElementException:
            return False

    def single_sms_go(self, studyid, overview=0):
        self.overview_go(self.overview_names()[0])
        overview = self.overviews()[overview]
        overview.sms_link(studyid).click()
        return [option.get_attribute('data-number')
                for option in self.els_xpath('//option[@data-number]')]

    def single_sms_send(self, message, number_idx=None):
        self.el_xpath('//textarea').send_keys(message)
        if number_idx is not None:
            s = Select(self.el_css('select.number'))
            s.select_by_index(number_idx)
        a = self.el_css_wait('.controls a')
        n = len(self.alerts())
        a.click()
        return self.el_css_wait('.alerts .alert:nth-child(%d)' % (n + 1)).text

    # data related {{{2

    def data_open(self, studyid, formid, overview=0):
        overview = self.overviews()[overview]
        a = overview.element(studyid, formid, link=True)
        a.click()

    def data_dict(self, studyid, formid, overview=0):
        self.data_open(studyid, formid, overview)
        d = {}
        for i, tr in enumerate(self.els_xpath('//table//tr')):
            datagroup = tr.get_attribute('data-group')
            tds = self.els_xpath('//table//tr[%d]/td' % (i+1))
            if len(tds) == 3:
                name = tds[0].text
                value = tds[2].text
                d.setdefault(datagroup, {})[name] = value
        #self.close_others()
        return d


    # form related {{{2

    def delete_form(self, formid):
        self.menu_go('forms')
        self.el_xpath('//tr[@data-formid = "%s"]//a[@class = "formdelete"]' %
                formid).click()

    def formids(self):
        self.menu_go('forms')
        return [
                td.text
                for td in self.els_xpath('//tr[@data-formid]/td[1]')
            ]

    def form_missing(self):
        self.menu_go('forms')
        return [
                a.find_element_by_xpath('ancestor::tr[1]').get_attribute('data-formid')
                for a in self.els_xpath('//a[@class = "unmatched-form"]')
            ]

    def form_xxx_only(self, xxx):
        self.menu_go('forms')
        ret = dict()
        for row in self.els_xpath('//tr[@data-formid]'):
            formid = row.get_attribute('data-formid')
            xpath = '//tr[@data-formid = "%s"]//button[@data-%s-only]' % (formid, xxx)
            for e in self.els_xpath(xpath):
                attr = e.get_attribute('data-%s-only' % xxx)
                ret[formid] = attr.split(',')
        return ret

    def form_db_only(self):
        return self.form_xxx_only('db')

    def form_xls_only(self):
        return self.form_xxx_only('xls')

    def download_form(self, formid):
        path = os.path.join(self.downloads, formid + '.xls')
        if os.path.exists(path):
            os.unlink(path)
        self.get('?formdownload=%s' % formid)
        assert os.path.exists(path)
        return path

    def upload_form(self, path):
        self.menu_go('forms')
        fileinput = self.el_xpath('//input[@name="formupload"]')
        fileinput.send_keys(os.path.abspath(path))
        button = self.el_xpath('//button[@type="submit"]')
        button.click()


    # config related {{{2

    def download_config(self):
        path = os.path.join(self.downloads, 'config.xls')
        if os.path.exists(path):
            os.unlink(path)
        self.get('?configdownload')
        assert os.path.exists(path)
        return path

    def upload_config(self, path):
        self.menu_go('admin')
        fileinput = self.el_xpath('//input[@name="configupload"]')
        fileinput.send_keys(os.path.abspath(path))
        button = self.el_xpath('//button[@type="submit"]')
        button.click()


    # alerts/log related {{{2

    def log_messages(self, name):
        self.menu_go('admin')
        for toggle in self.els_xpath('//a[@class = "accordion-toggle"]'):
            toggle.click()
        return [
                td.text
                for td in self.els_xpath('//table[@data-name = "user"]//td[3]')
            ]

    def alerts(self):
        return [
                e.text.strip(u'\n\r\t \xd7') # whitespace & close button
                for e in self.els_xpath('//div[@class="alerts"]/div')
            ]


# PlannerBackend {{{1

class PlannerBackend:

    ''' directly manipulate odk_planner configuration files '''

    def __init__(self, root):
        ''' :param root: path where odk_planner's ``index.php`` is located
            :param group: group name or group id (or empty or None) '''
        for part in ['index.php', 'VERSION', 'odk_form.php']:
            assert os.path.exists(os.path.join(root, part))

        self.root = root

    def create_instance(self, instance, group=None):
        dirs = (
                self.instancedir(instance),
                self.formdir(instance),
                self.configdir(instance),
                self.logdir(instance),
            )
        old_umask = os.umask(2) # create files/directories with g+w perms
        for path in dirs:
            os.mkdir(path)
        os.umask(old_umask)
        lo.info('created instance "%s"' % instance)

    def instancedir(self, instance):
        return os.path.join(self.root, 'instances', instance)

    def formdir(self, instance):
        return os.path.join(self.instancedir(instance), 'forms')

    def configdir(self, instance):
        return os.path.join(self.instancedir(instance), 'config')

    def configxls(self, instance):
        return os.path.join(self.configdir(instance), 'config.xls')

    def configini(self, instance):
        return os.path.join(self.configdir(instance), 'config.ini')

    def logdir(self, instance):
        return os.path.join(self.instancedir(instance), 'log')

    def isinstance(self, instance):
        return os.path.isdir(self.instancedir(instance))

    def chmodgw(self, path):
        ''' adds write permission for group to this file '''
        st = os.stat(path)
        os.chmod(path, st.st_mode | stat.S_IWGRP)

    def copyconfigxls(self, instance, path):
        dst = os.path.join(self.configdir(instance), 'config.xls')
        shutil.copyfile(path, dst)
        self.chmodgw(dst)
        lo.debug('copied config %s -> %s' % (path, dst))

    def copyconfigini(self, instance, path):
        dst = os.path.join(self.configdir(instance), 'config.ini')
        shutil.copyfile(path, dst)
        lo.debug('copied config %s -> %s' % (path, dst))

    def copyxls(self, instance, path):
        fname = os.path.basename(path)
        dst = os.path.join(self.formdir(instance), fname)
        shutil.copyfile(path, dst)
        self.chmodgw(dst)
        lo.debug('copied .xls %s -> %s' % (path, dst))

    def lsxls(self, instance):
        return os.listdir(self.formdir(instance))

    def rmxls(self, instance, fname):
        os.unlink(os.path.join(self.formdir(instance), fname))

