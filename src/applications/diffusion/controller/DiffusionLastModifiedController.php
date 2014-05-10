<?php

final class DiffusionLastModifiedController extends DiffusionController {

  public function shouldAllowPublic() {
    return true;
  }

  public function processRequest() {
    $drequest = $this->getDiffusionRequest();
    $request = $this->getRequest();

    $paths = $request->getStr('paths');
    $paths = json_decode($paths, true);
    if (!is_array($paths)) {
      return new Aphront400Response();
    }

    $commits = array();
    foreach ($paths as $path) {
      $prequest = clone $drequest;
      $prequest->setPath($path);

      $conduit_result = $this->callConduitWithDiffusionRequest(
        'diffusion.lastmodifiedquery',
        array(
          'commit' => $prequest->getCommit(),
          'path' => $prequest->getPath(),
        ));

      $commit = PhabricatorRepositoryCommit::newFromDictionary(
        $conduit_result['commit']);

      $commit_data = PhabricatorRepositoryCommitData::newFromDictionary(
        $conduit_result['commitData']);

      $commit->attachCommitData($commit_data);

      $phids = array();
      if ($commit_data) {
        if ($commit_data->getCommitDetail('authorPHID')) {
          $phids[$commit_data->getCommitDetail('authorPHID')] = true;
        }
        if ($commit_data->getCommitDetail('committerPHID')) {
          $phids[$commit_data->getCommitDetail('committerPHID')] = true;
        }
      }

      $commits[$path] = $commit;
    }

    $phids = array_keys($phids);
    $handles = $this->loadViewerHandles($phids);

    $branch = $drequest->loadBranch();
    if ($branch) {
      $lint_query = id(new DiffusionLintCountQuery())
        ->withBranchIDs(array($branch->getID()))
        ->withPaths(array_keys($commits));

      if ($drequest->getLint()) {
        $lint_query->withCodes(array($drequest->getLint()));
      }

      $lint = $lint_query->execute();
    } else {
      $lint = array();
    }

    $output = array();
    foreach ($commits as $path => $commit) {
      $prequest = clone $drequest;
      $prequest->setPath($path);

      $output[$path] = $this->renderColumns(
        $prequest,
        $handles,
        $commit,
        idx($lint, $path));
    }

    return id(new AphrontAjaxResponse())->setContent($output);
  }

  private function renderColumns(
    DiffusionRequest $drequest,
    array $handles,
    PhabricatorRepositoryCommit $commit = null,
    $lint = null) {
    assert_instances_of($handles, 'PhabricatorObjectHandle');
    $viewer = $this->getRequest()->getUser();

    if ($commit) {
      $epoch = $commit->getEpoch();
      $modified = DiffusionView::linkCommit(
        $drequest->getRepository(),
        $commit->getCommitIdentifier());
      $date = phabricator_date($epoch, $viewer);
      $time = phabricator_time($epoch, $viewer);
    } else {
      $modified = '';
      $date = '';
      $time = '';
    }

    $data = $commit->getCommitData();
    if ($data) {
      $author_phid = $data->getCommitDetail('authorPHID');
      if ($author_phid && isset($handles[$author_phid])) {
        $author = $handles[$author_phid]->renderLink();
      } else {
        $author = DiffusionView::renderName($data->getAuthorName());
      }

      $committer = $data->getCommitDetail('committer');
      if ($committer) {
        $committer_phid = $data->getCommitDetail('committerPHID');
        if ($committer_phid && isset($handles[$committer_phid])) {
          $committer = $handles[$committer_phid]->renderLink();
        } else {
          $committer = DiffusionView::renderName($committer);
        }
        if ($author != $committer) {
          $author = hsprintf('%s/%s', $author, $committer);
        }
      }

      $details = AphrontTableView::renderSingleDisplayLine($data->getSummary());
    } else {
      $author = '';
      $details = '';
    }

    $return = array(
      'commit'    => $modified,
      'date'      => $date,
      'time'      => $time,
      'author'    => $author,
      'details'   => $details,
    );

    if ($lint !== null) {
      $return['lint'] = phutil_tag(
        'a',
        array('href' => $drequest->generateURI(array(
          'action' => 'lint',
          'lint' => null,
        ))),
        number_format($lint));
    }

    return $return;
  }

}
